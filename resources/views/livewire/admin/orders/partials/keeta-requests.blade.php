{{-- Solicitações do cliente Keeta: reembolso (a loja aceita ou recusa; vale a primeira resposta) e cancelamento (decidido pela Keeta). --}}
@foreach ($this->keetaRequests as $request)
    @php
        $answerable = $request->isAnswerable();
        $isCancel = $request->kind === \App\Models\KeetaRequest::KIND_CANCEL;
        $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
    @endphp
    <div wire:key="keeta-request-{{ $request->id }}" data-testid="keeta-request"
         @class([
             'rounded-xl border p-4 space-y-3',
             'border-yellow-300 bg-yellow-50 dark:border-yellow-700 dark:bg-yellow-900/20' => $request->isOpen(),
             'border-neutral-200 bg-white dark:border-zinc-700 dark:bg-zinc-800' => ! $request->isOpen(),
         ])>
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-yellow-700 dark:text-yellow-400">Solicitação do cliente · Keeta</p>
                <p class="font-semibold text-neutral-800 dark:text-neutral-100">{{ $isCancel ? 'Cancelar o pedido' : 'Reembolso' }}@if ($request->amount) · {{ $money($request->amount) }}@endif</p>
                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $request->statusLabel() }}</p>
            </div>
            @if ($answerable && $request->expires_at)
                <p class="text-xs text-neutral-500 dark:text-neutral-400">Responder até {{ $request->expires_at->timezone(config('app.timezone'))->format('d/m H:i') }}</p>
            @endif
        </div>

        @if ($request->reason)
            <p class="rounded-lg bg-white/70 px-3 py-2 text-sm text-neutral-700 dark:bg-zinc-900/40 dark:text-neutral-200">“{{ $request->reason }}”</p>
        @endif

        @if (! empty($request->items))
            <ul class="list-disc pl-5 text-sm text-neutral-700 dark:text-neutral-300">
                @foreach ($request->items as $item)
                    <li>{{ $item['quantity'] ?? 1 }}x {{ $item['name'] ?? 'Item' }}@if (! empty($item['amount'])) · {{ $money($item['amount']) }}@endif</li>
                @endforeach
            </ul>
        @endif

        @if ($isCancel && $request->isOpen())
            <p class="text-xs text-neutral-500 dark:text-neutral-400">A Keeta decide o cancelamento pedido pelo cliente. Se confirmar, o pedido é cancelado aqui automaticamente.</p>
        @elseif ($request->response_reason)
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Motivo da recusa: {{ $request->response_reason }}</p>
        @endif

        @if ($answerable && $canUpdate && ! $userStation)
            @if ($keetaRejectRequestId === $request->id)
                <div class="space-y-2 rounded-lg border border-neutral-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200" for="keeta-reject-code-{{ $request->id }}">Motivo da recusa</label>
                    <select id="keeta-reject-code-{{ $request->id }}" wire:model="keetaRejectCode" class="w-full rounded-lg border-neutral-300 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                        <option value="">Selecione o motivo</option>
                        @foreach (\App\Models\KeetaRequest::REJECT_CODES as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200" for="keeta-reject-reason-{{ $request->id }}">Explique ao cliente por que recusa</label>
                    <textarea id="keeta-reject-reason-{{ $request->id }}" wire:model="keetaRejectReason" rows="2" maxlength="200"
                              class="w-full rounded-lg border-neutral-300 text-sm dark:border-zinc-600 dark:bg-zinc-900"></textarea>
                    @error('keetaRejectReason') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <div class="flex justify-end gap-2">
                        <flux:button size="sm" variant="ghost" wire:click="closeKeetaRequestReject">Voltar</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="confirmKeetaRequestReject" wire:target="confirmKeetaRequestReject">Enviar recusa</flux:button>
                    </div>
                </div>
            @else
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" variant="primary" wire:click="acceptKeetaRequest({{ $request->id }})" wire:target="acceptKeetaRequest({{ $request->id }})">Aceitar reembolso</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="openKeetaRequestReject({{ $request->id }})">Recusar</flux:button>
                </div>
            @endif
        @endif
    </div>
@endforeach
