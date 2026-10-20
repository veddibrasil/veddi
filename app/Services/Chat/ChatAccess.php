<?php

namespace App\Services\Chat;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatAccess
{
    public function customer(int $companyId, bool $recent = false): ?Customer
    {
        $identity = session("chat_identity.{$companyId}");
        if (! $identity || $identity['expires_at'] <= now()->timestamp || ($recent && $identity['verified_at'] < now()->subMinutes(10)->timestamp)) {
            return null;
        }

        if (isset($identity['device_id']) && ! DB::table('chat_remembered_devices')->where('id', $identity['device_id'])->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
            return null;
        }

        return Customer::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereKey($identity['customer_id'])->where('phone', $identity['phone'])->first();
    }

    public function phoneVerified(int $companyId, string $phone): bool
    {
        $identity = session("chat_identity.{$companyId}");
        if (! $identity || $identity['phone'] !== $phone || $identity['expires_at'] <= now()->timestamp) {
            return false;
        }

        return $identity['customer_id'] === null || $this->customer($companyId) !== null;
    }

    public function verify(int $companyId, string $phone): ?Customer
    {
        session()->regenerate();
        $customer = Customer::withoutGlobalScopes()->where('company_id', $companyId)->where('phone', $phone)->first();
        session(["chat_identity.{$companyId}" => [
            'customer_id' => $customer?->id, 'phone' => $phone,
            'verified_at' => now()->timestamp, 'expires_at' => now()->addHours(2)->timestamp,
        ]]);

        return $customer;
    }

    public function grantOrder(Order $order, bool $manage = true, ?int $accessId = null, ?int $expiresAt = null): void
    {
        $existing = session("chat_orders.{$order->company_id}.{$order->id}");
        if (! $manage && ($existing['manage'] ?? false) && $existing['expires_at'] > now()->timestamp) {
            return;
        }
        session(["chat_orders.{$order->company_id}.{$order->id}" => [
            'expires_at' => $expiresAt ?? now()->addDays(7)->timestamp, 'manage' => $manage, 'access_id' => $accessId,
        ]]);
    }

    public function canAccessOrder(Order $order, bool $manage = false): bool
    {
        $grant = session("chat_orders.{$order->company_id}.{$order->id}");
        if ($grant && ($grant['access_id'] ?? null) && ! DB::table('chat_order_access')->where('id', $grant['access_id'])->where('order_id', $order->id)->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
            $grant = null;
        }
        if ($grant && $grant['expires_at'] > now()->timestamp && (! $manage || $grant['manage'])) {
            return true;
        }

        $customer = $this->customer($order->company_id);

        return $customer && $order->customer_id === $customer->id;
    }

    public function orderLink(Order $order): string
    {
        $token = Str::random(64);
        DB::table('chat_order_access')->insert([
            'order_id' => $order->id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Fragment keeps the bearer token out of access logs and HTTP referrers.
        return route('chat.tracking', ['company' => $order->company->slug]).'#pedido='.$token;
    }

    public function redeemOrderLink(int $companyId, #[\SensitiveParameter] string $token): ?Order
    {
        if (strlen($token) !== 64) {
            return null;
        }
        $access = DB::table('chat_order_access')->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        $order = $access ? Order::withoutGlobalScopes()->where('company_id', $companyId)->find($access->order_id) : null;
        if ($order) {
            $this->grantOrder($order, false, $access->id, strtotime($access->expires_at));
        }

        return $order;
    }
}
