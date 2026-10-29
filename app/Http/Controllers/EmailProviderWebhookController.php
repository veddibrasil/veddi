<?php

namespace App\Http\Controllers;

use App\Contracts\EmailMarketingProviderInterface;
use App\Exceptions\InvalidEmailWebhookException;
use App\Jobs\ProcessEmailProviderWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Webhook do provedor de e-mail marketing (entrega, bounce, spam). Sem auth, sem CSRF. */
class EmailProviderWebhookController extends Controller
{
    public function __invoke(Request $request, EmailMarketingProviderInterface $provider): JsonResponse
    {
        try {
            $events = $provider->parseWebhook($request);
        } catch (InvalidEmailWebhookException $exception) {
            Log::channel('marketing')->warning('Webhook de e-mail recusado', [
                'provider' => $provider->name(),
                'reason' => $exception->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        if ($events !== []) {
            ProcessEmailProviderWebhook::dispatch($events);
        }

        return response()->json(['status' => 'queued']);
    }
}
