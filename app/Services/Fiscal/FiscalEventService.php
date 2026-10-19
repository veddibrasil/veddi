<?php

namespace App\Services\Fiscal;

use App\Models\CompanyFiscalConfig;
use App\Models\FiscalNote;
use App\Models\FiscalNoteEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FiscalEventService
{
    public function correction(FiscalNote $note, string $text): FiscalNoteEvent
    {
        validator(['text' => $text], ['text' => 'required|string|min:15|max:1000'])->validate();
        if ($note->model !== '55' || $note->status !== 'authorized') {
            throw new \RuntimeException('Carta de correção disponível somente para NF-e autorizada.');
        }
        $config = app(FiscalConfigResolver::class)->forNote($note);
        if (! $config) {
            throw new \RuntimeException('Configuração do emitente não encontrada.');
        }

        return DB::transaction(function () use ($note, $text, $config) {
            $note = FiscalNote::withoutGlobalScopes()->lockForUpdate()->findOrFail($note->id);
            if ($note->status !== 'authorized') {
                throw new \RuntimeException('A nota não está mais autorizada.');
            }
            if ($note->events()->where('type', 'correction')->where('status', 'pending')->exists()) {
                throw new \RuntimeException('Há uma carta de correção pendente. Consulte a nota antes de tentar novamente.');
            }

            return $this->send($config, $note->environment, 'nfe/'.rawurlencode($note->provider_reference).'/carta_correcao', [
                'correcao' => $text,
            ], $note);
        });
    }

    public function invalidate(CompanyFiscalConfig $config, array $data): FiscalNoteEvent
    {
        $data = validator($data, [
            'serie' => 'required|integer|min:1|max:999',
            'numero_inicial' => 'required|integer|min:1|max:999999999',
            'numero_final' => 'required|integer|gte:numero_inicial|max:999999999',
            'justificativa' => 'required|string|min:15|max:255',
        ])->validate();
        if (! $config->enabled || ! $config->nfe_enabled) {
            throw new \RuntimeException('NF-e não habilitada para o emitente.');
        }
        $branch = $config->branch;
        $data['cnpj'] = preg_replace('/\D/', '', $branch?->cnpj ?? '');
        if (strlen($data['cnpj']) !== 14) {
            throw new \RuntimeException('CNPJ do emitente não configurado.');
        }

        return DB::transaction(function () use ($config, $data) {
            CompanyFiscalConfig::whereKey($config->id)->lockForUpdate()->firstOrFail();
            $environment = FiscalNoteService::resolveEffectiveEnvironment($config->environment);
            $events = FiscalNoteEvent::where('company_id', $config->company_id)->where('fiscal_config_id', $config->id)
                ->where('type', 'invalidation')->whereIn('status', ['pending', 'authorized'])->get();
            foreach ($events as $event) {
                $request = $event->data['request'];
                if (($event->data['environment'] ?? '') === $environment && (int) $request['serie'] === (int) $data['serie']
                    && (int) $request['numero_inicial'] <= (int) $data['numero_final']
                    && (int) $request['numero_final'] >= (int) $data['numero_inicial']) {
                    throw new \RuntimeException('Esta faixa já possui inutilização autorizada ou pendente. Consulte o histórico antes de repetir.');
                }
            }

            return $this->send($config, null, 'nfe/inutilizacao', $data);
        });
    }

    public function reconcile(FiscalNoteEvent $event): FiscalNoteEvent
    {
        if ($event->status !== 'pending') {
            return $event;
        }
        $config = CompanyFiscalConfig::where('company_id', $event->company_id)->find($event->fiscal_config_id);
        if (! $config) {
            return $event;
        }
        $environment = FiscalNoteService::resolveEffectiveEnvironment($event->data['environment'] ?? $config->environment);
        $token = $config->tokenFor($environment) ?: config('fiscal.focus_nfe.token');
        if (! $token) {
            return $event;
        }
        $client = Http::withBasicAuth($token, '')->timeout(15);
        $baseUrl = FiscalNoteService::resolveBaseUrl($environment).'/v2/';
        try {
            if ($event->type === 'invalidation') {
                $request = $event->data['request'];
                $response = $client->get($baseUrl.'nfe/inutilizacoes', [
                    'cnpj' => $request['cnpj'], 'numero_inicial' => $request['numero_inicial'], 'numero_final' => $request['numero_final'],
                ]);
                if (! $response->successful()) {
                    return $event;
                }
                foreach ($response->json() ?? [] as $row) {
                    if ((string) ($row['cnpj'] ?? '') === (string) $request['cnpj']
                        && (int) ($row['serie'] ?? 0) === (int) $request['serie']
                        && (int) ($row['numero_inicial'] ?? 0) === (int) $request['numero_inicial']
                        && (int) ($row['numero_final'] ?? 0) === (int) $request['numero_final']
                        && (string) ($row['modelo'] ?? '') === '55') {
                        $event->update(['status' => ($row['status'] ?? '') === 'autorizado' ? 'authorized' : 'error', 'data' => array_merge($event->data, ['response' => $row])]);
                        break;
                    }
                }
            } elseif ($event->type === 'correction') {
                $note = FiscalNote::withoutGlobalScopes()->where('company_id', $event->company_id)->find($event->fiscal_note_id);
                if (! $note) {
                    return $event;
                }
                $response = $client->get($baseUrl.'nfe/'.rawurlencode($note->provider_reference), ['completa' => 1]);
                $body = $response->json() ?? [];
                if ($response->successful() && ($body['requisicao_carta_correcao']['correcao'] ?? null) === ($event->data['request']['correcao'] ?? null)
                    && ! empty($body['caminho_xml_carta_correcao'])) {
                    $event->update(['status' => 'authorized', 'data' => array_merge($event->data, ['response' => $body])]);
                }
            }
        } catch (\Illuminate\Http\Client\ConnectionException) {
            // Unknown results remain pending and cannot be sent again blindly.
        }

        return $event->fresh();
    }

    private function send(CompanyFiscalConfig $config, ?string $environment, string $path, array $payload, ?FiscalNote $note = null): FiscalNoteEvent
    {
        $environment = FiscalNoteService::resolveEffectiveEnvironment($environment ?: $config->environment);
        $token = $config->tokenFor($environment) ?: config('fiscal.focus_nfe.token');
        if (! $token) {
            throw new \RuntimeException('Token fiscal não configurado.');
        }
        $event = FiscalNoteEvent::create([
            'company_id' => $config->company_id,
            'fiscal_note_id' => $note?->id,
            'fiscal_config_id' => $config->id,
            'user_id' => auth()->id(),
            'type' => $note ? 'correction' : 'invalidation',
            'status' => 'pending',
            'data' => ['request' => $payload, 'environment' => $environment],
        ]);
        try {
            $response = Http::withBasicAuth($token, '')->timeout(15)
                ->post(FiscalNoteService::resolveBaseUrl($environment).'/v2/'.$path, $payload);
            $body = $response->json() ?? [];
            $accepted = in_array($body['status'] ?? '', ['autorizado', 'autorizado_com_vinculacao', 'inutilizado'], true);
            $event->update([
                'status' => $accepted ? 'authorized' : ($response->serverError() || $response->status() === 202 ? 'pending' : 'error'),
                'data' => array_merge($event->data, ['response' => $body]),
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $event->update(['data' => array_merge($event->data, ['error' => 'Comunicação interrompida. Consulte o resultado no provedor antes de repetir.'])]);
        }

        return $event->fresh();
    }
}
