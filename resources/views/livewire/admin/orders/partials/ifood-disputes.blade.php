{{-- Plataforma de Negociação do iFood: pedido do cliente (cancelamento/reembolso) com prazo pra loja responder. --}}
@foreach ($this->ifoodDisputes as $dispute)
    @php
        $open = $dispute->isOpen();
        $alternatives = collect($dispute->alternatives ?? []);
        $items = collect($dispute->metadata['items'] ?? []);
        $evidences = collect($dispute->metadata['evidences'] ?? [])->pluck('url')->filter();
    @endphp
    <div wire:key="ifood-dispute-{{ $dispute->id }}" data-testid="ifood-dispute"
         @class([
             'rounded-xl border p-4 space-y-3',
             'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20' => $open,
             'border-neutral-200 bg-white dark:border-zinc-700 dark:bg-zinc-800' => ! $open,
         ])>
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">Negociação iFood</p>
                <p class="font-semibold text-neutral-800 dark:text-neutral-100">{{ $dispute->actionLabel() }}</p>
            </div>
            @if ($open && $dispute->expires_at)
                <div x-data="acceptanceCountdown({{ $dispute->expires_at->getTimestampMs() }})" class="text-right">
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">Responder até {{ $dispute->expires_at->format('H:i') }}</p>
                    <p class="font-mono text-xl font-bold" :class="urgent ? 'text-red-600 dark:text-red-400' : 'text-neutral-800 dark:text-neutral-100'" x-text="label"></p>
                </div>
            @endif
        </div>

        @if ($dispute->message)
            <p class="rounded-lg bg-white/70 px-3 py-2 text-sm text-neutral-700 dark:bg-zinc-900/40 dark:text-neutral-200">“{{ $dispute->message }}”</p>
        @endif

        @if ($items->isNotEmpty())
            <ul class="text-sm text-neutral-700 dark:text-neutral-300 list-disc pl-5">
                @foreach ($items as $item)
                    <li>
                        {{ $item['quantity'] ?? '' }}{{ isset($item['quantity']) ? 'x ' : '' }}{{ $item['name'] ?? ($order->items[(int) ($item['index'] ?? 0) - 1]->product_name ?? 'Item') }}
                        @if (! empty($item['reason'])) <span class="text-neutral-500">— {{ $item['reason'] }}</span> @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($evidences->isNotEmpty())
            <div class="flex flex-wrap gap-2 text-sm">
                @foreach ($evidences as $url)
                    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-amber-700 underline dark:text-amber-400">Evidência {{ $loop->iteration }}</a>
                @endforeach
            </div>
        @endif

        @if ($open && $dispute->timeoutLabel())
            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $dispute->timeoutLabel() }}</p>
        @endif

        @if ($open && $canUpdate)
            @if ($disputeId === $dispute->id)
                <div class="space-y-2 rounded-lg border border-neutral-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                    @if ($disputeMode === 'accept')
                        <p class="text-sm text-neutral-700 dark:text-neutral-200">Aceitar o pedido do cliente no iFood.</p>
                        @if ($dispute->acceptReasons() !== [])
                            <select wire:model="disputeReason" class="w-full rounded-lg border-neutral-300 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                                <option value="">Selecione o motivo</option>
                                @foreach ($dispute->acceptReasons() as $reason)
                                    <option value="{{ $reason }}">{{ $reason }}</option>
                                @endforeach
                            </select>
                        @endif
                    @elseif ($disputeMode === 'reject')
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200" for="dispute-reason-{{ $dispute->id }}">Explique ao cliente por que recusa</label>
                        <textarea id="dispute-reason-{{ $dispute->id }}" wire:model="disputeReason" rows="2" maxlength="250"
                                  class="w-full rounded-lg border-neutral-300 text-sm dark:border-zinc-600 dark:bg-zinc-900"></textarea>
                    @else
                        @php $alternative = $alternatives->firstWhere('id', $disputeAlternativeId); @endphp
                        @if (($alternative['type'] ?? null) === 'ADDITIONAL_TIME')
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <select wire:model="disputeMinutes" class="rounded-lg border-neutral-300 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                                    <option value="">Tempo adicional</option>
                                    @foreach ($alternative['metadata']['allowedsAdditionalTimeInMinutes'] ?? [] as $minutes)
                                        <option value="{{ $minutes }}">{{ $minutes }} min</option>
                                    @endforeach
                                </select>
                                <select wire:model="disputeTimeReason" class="rounded-lg border-neutral-300 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                                    <option value="">Motivo do atraso</option>
                                    @foreach ($alternative['metadata']['allowedsAdditionalTimeReasons'] ?? [] as $timeReason)
                                        <option value="{{ $timeReason }}">{{ $timeReason }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @else
                            @php $max = isset($alternative['metadata']['maxAmount']['value']) ? ((int) $alternative['metadata']['maxAmount']['value']) / 100 : null; @endphp
                            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200" for="dispute-amount-{{ $dispute->id }}">
                                Valor a {{ ($alternative['type'] ?? '') === 'BENEFIT' ? 'oferecer em benefício' : 'reembolsar' }}
                                @if ($max !== null) (até R$ {{ number_format($max, 2, ',', '.') }}) @endif
                            </label>
                            <input id="dispute-amount-{{ $dispute->id }}" type="text" inputmode="decimal" wire:model="disputeAmount" placeholder="0,00"
                                   class="w-full rounded-lg border-neutral-300 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                        @endif
                    @endif

                    @error('disputeReason') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                    <div class="flex justify-end gap-2">
                        <button wire:click="closeDisputeResponse" class="px-3 py-2 text-sm text-neutral-600 hover:text-neutral-800 dark:text-neutral-400">Voltar</button>
                        <button wire:click="submitDisputeResponse" wire:loading.attr="disabled"
                                class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-50">
                            <span wire:loading.remove wire:target="submitDisputeResponse">Enviar ao iFood</span>
                            <span wire:loading wire:target="submitDisputeResponse">Enviando...</span>
                        </button>
                    </div>
                </div>
            @else
                <div class="flex flex-wrap gap-2">
                    <button wire:click="openDisputeResponse({{ $dispute->id }}, 'accept')"
                            class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">Aceitar</button>
                    <button wire:click="openDisputeResponse({{ $dispute->id }}, 'reject')"
                            class="rounded-lg bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 dark:bg-red-900/30 dark:text-red-400">Recusar</button>
                    @foreach ($alternatives as $alternative)
                        <button wire:click="openDisputeResponse({{ $dispute->id }}, 'alternative', @js((string) ($alternative['id'] ?? '')))"
                                class="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-amber-800 ring-1 ring-amber-300 hover:bg-amber-100 dark:bg-zinc-800 dark:text-amber-300 dark:ring-amber-700">
                            {{ match ($alternative['type'] ?? '') { 'REFUND' => 'Propor reembolso', 'BENEFIT' => 'Propor benefício', 'ADDITIONAL_TIME' => 'Propor mais tempo', default => 'Contraproposta' } }}
                        </button>
                    @endforeach
                </div>
            @endif
        @elseif ($dispute->status === \App\Models\IfoodDispute::STATUS_RESPONDED)
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                Resposta enviada ({{ match ($dispute->response) { 'ACCEPTED' => 'aceita', 'REJECTED' => 'recusada', default => 'contraproposta' } }})
                {{ $dispute->responded_at?->format('d/m H:i') }}{{ $dispute->respondedBy ? ' por '.$dispute->respondedBy->name : '' }}. Aguardando o iFood.
            </p>
        @elseif ($dispute->settlementLabel())
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                Desfecho: <strong>{{ $dispute->settlementLabel() }}</strong>{{ $dispute->settled_at ? ' em '.$dispute->settled_at->format('d/m H:i') : '' }}.
            </p>
        @else
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Prazo de resposta encerrado.</p>
        @endif
    </div>
@endforeach
