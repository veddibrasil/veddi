<?php

namespace App\Http\Controllers;

use App\Services\AsaasCircuitBreaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class HealthController extends Controller
{
    public function __invoke(AsaasCircuitBreaker $circuitBreaker): JsonResponse
    {
        $checks = [];
        $healthy = true;

        // Database
        try {
            DB::select('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'fail';
            $healthy = false;
        }

        // Redis / Cache
        try {
            Cache::put('health_check', 1, 5);
            Cache::forget('health_check');
            $checks['redis'] = 'ok';
        } catch (\Throwable $e) {
            $checks['redis'] = 'fail';
            $healthy = false;
        }

        // Queue (Horizon / Redis queue connection)
        try {
            Queue::size();
            $checks['queue'] = 'ok';
        } catch (\Throwable $e) {
            $checks['queue'] = 'fail';
            $healthy = false;
        }

        // Asaas circuit breaker state — não fatal (app continua operando sem Asaas)
        $circuitState = $circuitBreaker->getState();
        $checks['asaas_circuit'] = $circuitState;
        $checks['asaas_failures'] = $circuitBreaker->getFailureCount();

        $status = $healthy ? 200 : 503;

        return response()->json([
            'status' => $healthy ? ($circuitState === 'open' ? 'degraded' : 'ok') : 'degraded',
            'checks' => $checks,
        ], $status);
    }
}
