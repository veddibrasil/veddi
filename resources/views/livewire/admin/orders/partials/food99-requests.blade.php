{{-- Solicitações do cliente 99Food (cancelamento: 5 min; reembolso: 24 h) com prazo pra loja responder. --}}
@foreach ($this->food99Requests as $request)
    @php
        $open = $request->isOpen();
        $isCancel = $request->kind === \App\Models\Food99Request::KIND_CANCEL;
    @endphp
    <div wire:key="food99-request-{{ $request->id }}" data-testid="food99-request"
         @class([
             'rounded-xl border p-4 space-y-3',
             'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20' => $open,
             'border-neutral-200 bg-white dark:border-zinc-700 dark:bg-zinc-800' => ! $open,
         ])>
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">Solicitação do cliente · 99Food</p>
                <p class="font-semibold text-neutral-800 dark:text-neutral-100">{{ $isCancel ? 'Cancelar o pedido' : 'Reembolso' }}</p>
                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $request->statusLabel() }}</p>
            </div>
            @if ($open && $request->expires_at)
                <div x-data="acceptanceCountdown({{ $request->expires_at->getTimestampMs() }})" class="text-right">
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">Responder até {{ $request->expires_at->timezone(config('app.timezone'))->format($isCancel ? 'H:i' : 'd/m H:i') }}</p>
                    <p class="font-mono text-xl font-bold" :class="urgent ? 'text-red-600 dark:text-red-400' : 'text-neutral-800 dark:text-neutral-100'" x-text="label"></p>
                </div>
            @endif
        </div>

        @if ($request->apply_reason)
            <p class="rounded-lg bg-white/70 px-3 py-2 text-sm text-neutral-700 dark:bg-zinc-900/40 dark:text-neutral-200">“{{ $request->apply_reason }}”</p>
        @endif

        @if (! empty($request->images))
            <div class="flex flex-wrap gap-2 text-sm">
                @foreach ($request->images as $url)
                    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-amber-700 underline dark:text-amber-400">Foto {{ $loop->iteration }}</a>
                @endforeach
            </div>
        @endif

        @if ($open)
            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                {{ $isCancel ? 'Sem resposta no prazo, a 99Food recusa o cancelamento.' : 'Sem resposta no prazo, a 99Food aceita o reembolso.' }}
            </p>
        @elseif ($request->response_reason)
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Motivo da recusa: {{ $request->response_reason }}</p>
        @endif

        @if ($open && $canUpdate && ! $userStation)
            @if ($food99RejectRequestId === $request->id)
                <div class="space-y-2 rounded-lg border border-neutral-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                    @if (! $isCancel)
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200" for="food99-base-reason-{{ $request->id }}">Motivo da recusa</label>
                        <select id="food99-base-reason-{{ $request->id }}" wire:model="food99RejectBaseReason" class="w-full rounded-lg border-neutral-300 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                            <option value="">Selecione o motivo</option>
                            @foreach ($request->reason_options ?? [] as $option)
                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    @endif
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200" for="food99-reject-reason-{{ $request->id }}">Explique ao cliente por que recusa</label>
                    <textarea id="food99-reject-reason-{{ $request->id }}" wire:model="food99RejectReason" rows="2" maxlength="200"
                              class="w-full rounded-lg border-neutral-300 text-sm dark:border-zinc-600 dark:bg-zinc-900"></textarea>
                    @error('food99RejectReason') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <div class="flex justify-end gap-2">
                        <flux:button size="sm" variant="ghost" wire:click="closeFood99RequestReject">Voltar</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="confirmFood99RequestReject" wire:target="confirmFood99RequestReject">Enviar recusa</flux:button>
                    </div>
                </div>
            @else
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" variant="primary" wire:click="acceptFood99Request({{ $request->id }})" wire:target="acceptFood99Request({{ $request->id }})">
                        {{ $isCancel ? 'Aceitar cancelamento' : 'Aceitar reembolso' }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="openFood99RequestReject({{ $request->id }})">Recusar</flux:button>
                </div>
            @endif
        @endif
    </div>
@endforeach
