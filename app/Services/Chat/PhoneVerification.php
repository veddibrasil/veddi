<?php

namespace App\Services\Chat;

use App\Contracts\ChatCodeSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

class PhoneVerification
{
    private function scope(int $companyId, #[\SensitiveParameter] string $phone): string
    {
        $nonce = session('chat_challenge_nonce');
        if (! $nonce) {
            session(['chat_challenge_nonce' => $nonce = Str::random(64)]);
        }

        return hash_hmac('sha256', "saved-profile|{$companyId}|{$phone}|{$nonce}", config('app.key'));
    }

    public function send(int $companyId, #[\SensitiveParameter] string $phone): string
    {
        if (! preg_match('/^\d{10,11}$/', $phone)) {
            throw new RuntimeException('Telefone inválido.');
        }
        $scope = $this->scope($companyId, $phone);

        return Cache::lock('chat-code-send:'.$scope, 15)->block(3, function () use ($companyId, $phone, $scope) {
            $phoneHash = hash_hmac('sha256', $phone, config('app.key'));
            $limits = [
                ["chat-otp:session:{$scope}", 1, 60],
                ["chat-otp:phone:{$companyId}:{$phoneHash}", 5, 600],
                ['chat-otp:ip:'.hash('sha256', (string) request()->ip()), 20, 600],
                ["chat-otp:company:{$companyId}", 60, 600],
                ['chat-otp:global', 1000, 600],
            ];
            foreach ($limits as [$key, $max, $seconds]) {
                if (RateLimiter::tooManyAttempts($key, $max)) {
                    throw new RuntimeException('Aguarde antes de solicitar outro código.');
                }
            }
            foreach ($limits as [$key, $max, $seconds]) {
                RateLimiter::hit($key, $seconds);
            }
            $id = (string) Str::uuid();
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            DB::transaction(function () use ($scope, $id, $companyId, $code) {
                DB::table('chat_phone_challenges')->where('scope_hash', $scope)->whereNull('consumed_at')->update(['consumed_at' => now()]);
                DB::table('chat_phone_challenges')->insert([
                    'id' => $id, 'company_id' => $companyId, 'scope_hash' => $scope,
                    'code_hash' => hash_hmac('sha256', $id.'|'.$code, config('app.key')),
                    'attempts' => 0, 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
            try {
                app(ChatCodeSender::class)->send($companyId, $phone, $code);
            } catch (\Throwable) {
                DB::table('chat_phone_challenges')->where('id', $id)->update(['consumed_at' => now()]);
                throw new RuntimeException('Não foi possível enviar o código. Você pode continuar preenchendo seus dados.');
            }

            return $id;
        });
    }

    public function consume(string $id, int $companyId, #[\SensitiveParameter] string $phone, #[\SensitiveParameter] string $code): bool
    {
        $scope = $this->scope($companyId, $phone);

        return DB::transaction(function () use ($id, $companyId, $scope, $code) {
            $challenge = DB::table('chat_phone_challenges')->where('id', $id)->where('company_id', $companyId)->where('scope_hash', $scope)->lockForUpdate()->first();
            if (! $challenge || $challenge->consumed_at || $challenge->attempts >= 5 || $challenge->expires_at <= now()->toDateTimeString()) {
                return false;
            }
            DB::table('chat_phone_challenges')->where('id', $id)->increment('attempts');
            if (! preg_match('/^\d{6}$/', $code) || ! hash_equals($challenge->code_hash, hash_hmac('sha256', $id.'|'.$code, config('app.key')))) {
                return false;
            }
            DB::table('chat_phone_challenges')->where('id', $id)->update(['consumed_at' => now()]);

            return true;
        });
    }
}
