<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZApiService
{
    private string $baseUrl = 'https://api.z-api.io';

    public function getQrCode(): ?string
    {
        try {
            $response = $this->http()->get($this->endpoint('qr-code'));

            if ($response->successful()) {
                return $response->json('value');
            }

            Log::channel('whatsapp')->warning('ZApiService::getQrCode falhou', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('ZApiService::getQrCode', ['error' => $e->getMessage()]);
        }

        return null;
    }

    public function getStatus(): string
    {
        try {
            $response = $this->http()->get($this->endpoint('status'));

            if ($response->successful()) {
                return ($response->json('connected') ?? false) ? 'open' : 'close';
            }
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('ZApiService::getStatus', ['error' => $e->getMessage()]);
        }

        return 'close';
    }

    public function getConnectedPhone(): ?string
    {
        try {
            $response = $this->http()->get($this->endpoint('status'));

            if ($response->successful()) {
                return $response->json('phone');
            }
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('ZApiService::getConnectedPhone', ['error' => $e->getMessage()]);
        }

        return null;
    }

    public function disconnect(): bool
    {
        try {
            $response = $this->http()->get($this->endpoint('disconnect'));

            return $response->successful();
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('ZApiService::disconnect', ['error' => $e->getMessage()]);
        }

        return false;
    }

    public function sendText(string $phone, string $message): bool
    {
        try {
            $response = $this->http()->post($this->endpoint('send-text'), [
                'phone' => $phone,
                'message' => $message,
            ]);

            if ($response->successful()) {
                Log::channel('whatsapp')->info('ZApiService: mensagem enviada', ['phone' => $phone]);

                return true;
            }

            Log::channel('whatsapp')->warning('ZApiService::sendText falhou', [
                'phone' => $phone,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('ZApiService::sendText', ['error' => $e->getMessage()]);
        }

        return false;
    }

    private function endpoint(string $path): string
    {
        $instanceId = config('services.zapi.instance_id');
        $token = config('services.zapi.token');

        return "{$this->baseUrl}/instances/{$instanceId}/token/{$token}/{$path}";
    }

    private function http()
    {
        return Http::withHeader('Client-Token', config('services.zapi.client_token'))->timeout(10);
    }
}
