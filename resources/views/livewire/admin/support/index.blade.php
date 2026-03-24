<div class="space-y-4">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Suporte</h1>
    </div>

    {{-- Filtros --}}
    <div class="flex gap-2">
        <button
            wire:click="$set('statusFilter', 'open')"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-colors
                {{ $statusFilter === 'open'
                    ? 'bg-amber-500 text-white'
                    : 'bg-white border border-neutral-200 text-neutral-600 hover:bg-neutral-50 dark:bg-zinc-800 dark:border-zinc-700 dark:text-neutral-300 dark:hover:bg-zinc-700' }}"
        >
            Abertos
        </button>
        <button
            wire:click="$set('statusFilter', 'closed')"
            class="px-4 py-2 rounded-lg text-sm font-medium transition-colors
                {{ $statusFilter === 'closed'
                    ? 'bg-amber-500 text-white'
                    : 'bg-white border border-neutral-200 text-neutral-600 hover:bg-neutral-50 dark:bg-zinc-800 dark:border-zinc-700 dark:text-neutral-300 dark:hover:bg-zinc-700' }}"
        >
            Fechados
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">

        {{-- Lista de tickets --}}
        <div class="lg:col-span-2 space-y-2">
            @forelse ($tickets as $ticket)
                @php $lastMsg = $ticket->messages->first(); @endphp
                <button
                    wire:click="selectTicket({{ $ticket->id }})"
                    class="w-full text-left bg-white border rounded-xl p-3 shadow-sm transition-all
                        {{ $selectedTicketId === $ticket->id
                            ? 'border-amber-400 ring-1 ring-amber-300 dark:border-amber-500'
                            : 'border-neutral-200 hover:border-amber-300 dark:border-zinc-700 dark:hover:border-amber-600' }}
                        dark:bg-zinc-800"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="font-semibold text-sm text-neutral-800 dark:text-neutral-100 truncate">
                                    {{ $ticket->customer->name }}
                                </p>
                                @if ($ticket->unread_count > 0)
                                    <span class="shrink-0 inline-flex items-center justify-center w-5 h-5 rounded-full bg-red-500 text-white text-[10px] font-bold">
                                        {{ $ticket->unread_count }}
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $ticket->customer->phone }}</p>
                            @if ($lastMsg)
                                <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-1 truncate">
                                    {{ $lastMsg->sender === 'admin' ? 'Você: ' : '' }}{{ $lastMsg->message }}
                                </p>
                            @endif
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-[10px] text-neutral-400 dark:text-neutral-500">
                                {{ $ticket->updated_at->diffForHumans() }}
                            </p>
                            <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-medium
                                {{ $ticket->status === 'open'
                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                    : 'bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400' }}">
                                {{ $ticket->status === 'open' ? 'Aberto' : 'Fechado' }}
                            </span>
                        </div>
                    </div>
                </button>
            @empty
                <div class="bg-white border border-neutral-200 rounded-xl p-8 text-center shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                    <p class="text-neutral-400 dark:text-neutral-500 text-sm">
                        Nenhum ticket {{ $statusFilter === 'open' ? 'aberto' : 'fechado' }} encontrado.
                    </p>
                </div>
            @endforelse

            {{ $tickets->links() }}
        </div>

        {{-- Painel de conversa --}}
        <div class="lg:col-span-3">
            @if ($selectedTicketId)
                @php $ticket = $tickets->firstWhere('id', $selectedTicketId) ?? \App\Models\SupportTicket::with('customer')->find($selectedTicketId); @endphp
                <div
                    class="bg-white border border-neutral-200 rounded-xl shadow-sm flex flex-col dark:bg-zinc-800 dark:border-zinc-700"
                    style="min-height: 500px;"
                    x-data="{ scrollToBottom() { const el = $refs.msgList; if (el) el.scrollTop = el.scrollHeight; } }"
                    x-init="scrollToBottom()"
                    x-on:livewire:updated.window="$nextTick(() => scrollToBottom())"
                >
                    {{-- Header da conversa --}}
                    <div class="px-4 py-3 border-b dark:border-zinc-700 flex items-center justify-between">
                        <div>
                            <p class="font-semibold text-neutral-700 dark:text-neutral-200 text-sm">
                                {{ $ticket?->customer->name }}
                            </p>
                            <p class="text-xs text-neutral-400 dark:text-neutral-500">
                                {{ $ticket?->customer->phone }}
                                • Ticket #{{ $selectedTicketId }}
                            </p>
                        </div>
                        <div>
                            @if ($ticket?->status === 'open')
                                <flux:modal.trigger name="confirm-close-ticket">
                                    <button
                                        class="text-xs bg-neutral-100 hover:bg-neutral-200 text-neutral-600 px-3 py-1.5 rounded-lg transition-colors dark:bg-zinc-700 dark:hover:bg-zinc-600 dark:text-neutral-300"
                                    >
                                        Fechar ticket
                                    </button>
                                </flux:modal.trigger>
                            @else
                                <button
                                    wire:click="reopenTicket({{ $selectedTicketId }})"
                                    class="text-xs bg-amber-100 hover:bg-amber-200 text-amber-700 px-3 py-1.5 rounded-lg transition-colors dark:bg-amber-900/30 dark:hover:bg-amber-900/50 dark:text-amber-400"
                                >
                                    Reabrir ticket
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- Mensagens --}}
                    <div
                        x-ref="msgList"
                        class="flex-1 overflow-y-auto px-4 py-3 space-y-2"
                        style="max-height: 380px;"
                    >
                        @forelse ($conversation as $msg)
                            <div class="flex {{ $msg['sender'] === 'admin' ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[80%] px-3 py-2 rounded-2xl text-sm leading-snug
                                    {{ $msg['sender'] === 'admin'
                                        ? 'bg-amber-500 text-white rounded-br-sm'
                                        : 'bg-neutral-100 text-neutral-800 rounded-bl-sm dark:bg-zinc-700 dark:text-neutral-100' }}">
                                    <p>{{ $msg['message'] }}</p>
                                    <p class="text-[10px] mt-0.5 {{ $msg['sender'] === 'admin' ? 'text-amber-100' : 'text-neutral-400 dark:text-neutral-500' }} text-right">
                                        {{ $msg['created_at'] }}
                                    </p>
                                </div>
                            </div>
                        @empty
                            <p class="text-center text-xs text-neutral-400 dark:text-neutral-500 py-8">
                                Nenhuma mensagem ainda.
                            </p>
                        @endforelse
                    </div>

                    {{-- Input de resposta --}}
                    @if ($ticket?->status === 'open')
                        <div class="border-t dark:border-zinc-700 px-4 py-3 flex gap-2">
                            <input
                                wire:model="replyMessage"
                                wire:keydown.enter="sendReply"
                                type="text"
                                placeholder="Digite uma resposta..."
                                class="flex-1 text-sm rounded-xl border border-neutral-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-400 dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-100 dark:placeholder-neutral-400"
                            />
                            <button
                                wire:click="sendReply"
                                wire:loading.attr="disabled"
                                wire:target="sendReply"
                                class="shrink-0 bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors"
                            >
                                <span wire:loading.remove wire:target="sendReply">Enviar</span>
                                <span wire:loading wire:target="sendReply">...</span>
                            </button>
                        </div>
                        @error('replyMessage')
                            <p class="text-xs text-red-500 px-4 pb-2">{{ $message }}</p>
                        @enderror
                    @else
                        <div class="border-t dark:border-zinc-700 px-4 py-3">
                            <p class="text-xs text-neutral-400 dark:text-neutral-500 text-center">
                                Ticket fechado. Reabra para responder.
                            </p>
                        </div>
                    @endif
                </div>
            @else
                <div class="bg-white border border-neutral-200 rounded-xl shadow-sm flex items-center justify-center dark:bg-zinc-800 dark:border-zinc-700" style="min-height: 300px;">
                    <div class="text-center">
                        <p class="text-4xl mb-3">💬</p>
                        <p class="text-neutral-400 dark:text-neutral-500 text-sm">Selecione um ticket para ver a conversa</p>
                    </div>
                </div>
            @endif
        </div>

    </div>

    <flux:modal name="confirm-close-ticket" class="max-w-sm">
        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">Fechar ticket?</flux:heading>
                    <flux:subheading class="mt-1">O ticket será marcado como fechado e o cliente será notificado.</flux:subheading>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-1">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:modal.close>
                    <flux:button wire:click="closeTicket({{ $selectedTicketId ?? 0 }})" variant="primary">Fechar ticket</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
