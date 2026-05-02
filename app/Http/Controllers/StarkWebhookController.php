<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessStarkWebhook;
use App\Services\Payment\StarkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use StarkBank\Event;
use StarkCore\Error\InvalidSignatureError;

class StarkWebhookController extends Controller
{
    public function __construct(private readonly StarkService $stark) {}

    public function __invoke(Request $request): JsonResponse
    {
        $content = $request->getContent();
        $signature = $request->header('Digital-Signature', '');

        if (empty($signature)) {
            Log::channel('webhook')->warning('Stark webhook: Digital-Signature header ausente', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $event = Event::parse($content, $signature);
        } catch (InvalidSignatureError $e) {
            Log::channel('webhook')->warning('Stark webhook: assinatura ECDSA inválida', [
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($content, true);
        $eventType = $data['event']['log']['type'] ?? ($data['event']['type'] ?? null);

        Log::channel('webhook')->info('Stark webhook recebido', [
            'event' => $eventType,
            'stark_event_id' => $event->id ?? null,
            'payload' => $data,
        ]);

        if (! $eventType) {
            return response()->json(['error' => 'Missing event'], 422);
        }

        ProcessStarkWebhook::dispatch($eventType, $data);

        Log::channel('webhook')->info('Stark webhook enfileirado', [
            'event' => $eventType,
            'invoice_id' => $data['event']['log']['invoice']['id'] ?? null,
        ]);

        return response()->json(['status' => 'queued']);
    }
}
