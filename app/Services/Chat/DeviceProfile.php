<?php

namespace App\Services\Chat;

use App\Models\ChatDeviceProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceProfile
{
    public const FIELDS = [
        'name', 'email', 'address', 'number', 'complement', 'neighborhood', 'city',
        'cep', 'taxId', 'customer_latitude', 'customer_longitude',
    ];

    public function save(int $companyId, #[\SensitiveParameter] string $phone, #[\SensitiveParameter] array $data): void
    {
        DB::transaction(function () use ($companyId, $phone, $data) {
            $profile = $this->query($companyId)->lockForUpdate()->first() ?? new ChatDeviceProfile;
            $token = Str::random(64);
            $profile->fill([
                'company_id' => $companyId,
                'token_hash' => hash('sha256', $token),
                'phone_hash' => hash_hmac('sha256', $phone, config('app.key')),
                'data' => array_intersect_key($data, array_flip(self::FIELDS)),
                'expires_at' => now()->addDays(30),
            ])->save();
            $this->cookie($companyId, $token, 30 * 24 * 60);
        });
    }

    public function find(int $companyId, #[\SensitiveParameter] string $phone): ?array
    {
        return DB::transaction(function () use ($companyId, $phone) {
            $profile = $this->query($companyId)->lockForUpdate()->first();
            if (! $profile || ! hash_equals($profile->phone_hash, hash_hmac('sha256', $phone, config('app.key')))) {
                return null;
            }
            $token = Str::random(64);
            $profile->update(['token_hash' => hash('sha256', $token)]);
            $this->cookie($companyId, $token, max(1, (int) ceil(($profile->expires_at->timestamp - now()->timestamp) / 60)));

            return $profile->data;
        });
    }

    public function forget(int $companyId): void
    {
        // Include expired records so forgetting also revokes them.
        ChatDeviceProfile::query()->where('company_id', $companyId)
            ->where('token_hash', hash('sha256', $this->token($companyId)))
            ->update(['revoked_at' => now()]);
        Cookie::queue(Cookie::forget("chat_device_profile_{$companyId}"));
    }

    private function query(int $companyId): Builder
    {
        return ChatDeviceProfile::query()->where('company_id', $companyId)
            ->where('token_hash', hash('sha256', $this->token($companyId)))
            ->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    private function token(int $companyId): string
    {
        // A rotation may already be queued earlier in this same request.
        $name = "chat_device_profile_{$companyId}";
        $token = Cookie::queued($name)?->getValue() ?? request()->cookie($name);

        return is_string($token) && strlen($token) === 64 ? $token : '';
    }

    private function cookie(int $companyId, #[\SensitiveParameter] string $token, int $minutes): void
    {
        Cookie::queue(cookie("chat_device_profile_{$companyId}", $token, $minutes, '/', null, true, true, false, 'lax'));
    }
}
