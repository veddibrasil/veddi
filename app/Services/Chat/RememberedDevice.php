<?php

namespace App\Services\Chat;

use App\Models\Customer;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RememberedDevice
{
    public function remember(Customer $customer): void
    {
        $token = Str::random(64);
        $id = DB::table('chat_remembered_devices')->insertGetId([
            'company_id' => $customer->company_id, 'customer_id' => $customer->id,
            'phone_hash' => hash_hmac('sha256', $customer->phone, config('app.key')),
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $identity = session("chat_identity.{$customer->company_id}");
        $identity['device_id'] = $id;
        session(["chat_identity.{$customer->company_id}" => $identity]);
        $this->cookie($customer->company_id, $token, 30 * 24 * 60);
    }

    public function restore(int $companyId): void
    {
        if (app(ChatAccess::class)->customer($companyId)) {
            return;
        }
        $token = request()->cookie("chat_device_{$companyId}");
        if (! is_string($token) || strlen($token) !== 64) {
            return;
        }
        DB::transaction(function () use ($companyId, $token) {
            $device = DB::table('chat_remembered_devices')->where('company_id', $companyId)
                ->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')
                ->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $device) {
                return;
            }
            $customer = Customer::withoutGlobalScopes()->where('company_id', $companyId)->find($device->customer_id);
            if (! $customer || ! hash_equals($device->phone_hash, hash_hmac('sha256', $customer->phone, config('app.key')))) {
                DB::table('chat_remembered_devices')->where('id', $device->id)->update(['revoked_at' => now()]);

                return;
            }
            $newToken = Str::random(64);
            DB::table('chat_remembered_devices')->where('id', $device->id)->update(['token_hash' => hash('sha256', $newToken), 'updated_at' => now()]);
            session()->regenerate();
            session(["chat_identity.{$companyId}" => [
                'customer_id' => $customer->id, 'phone' => $customer->phone, 'device_id' => $device->id,
                'verified_at' => 0, 'expires_at' => min(now()->addHours(2)->timestamp, strtotime($device->expires_at)),
            ]]);
            $this->cookie($companyId, $newToken, max(1, (int) ceil((strtotime($device->expires_at) - now()->timestamp) / 60)));
        });
    }

    public function forget(int $companyId): void
    {
        $deviceId = session("chat_identity.{$companyId}.device_id");
        $token = request()->cookie("chat_device_{$companyId}");
        DB::table('chat_remembered_devices')->where('company_id', $companyId)
            ->where(function ($q) use ($deviceId, $token) {
                $q->where('id', $deviceId)->orWhere('token_hash', hash('sha256', (string) $token));
            })->update(['revoked_at' => now()]);
        session()->forget(["chat_identity.{$companyId}", "chat_orders.{$companyId}", 'chat_challenge_nonce']);
        Cookie::queue(Cookie::forget("chat_device_{$companyId}"));
        session()->regenerate();
    }

    private function cookie(int $companyId, string $token, int $minutes): void
    {
        Cookie::queue(cookie("chat_device_{$companyId}", $token, $minutes, '/', null, true, true, false, 'lax'));
    }
}
