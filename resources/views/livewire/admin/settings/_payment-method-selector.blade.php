<div class="mt-3 grid grid-cols-3 gap-2">
    @foreach(['pix' => 'PIX', 'boleto' => 'Boleto', 'credit_card' => 'Cartão'] as $val => $lbl)
        <label
            class="flex items-center gap-2 border rounded-lg px-3 py-2.5 cursor-pointer transition-colors"
            :class="method === '{{ $val }}'
                ? 'border-[#7A00A3] bg-purple-50 dark:bg-purple-950/50 dark:border-purple-400'
                : 'border-zinc-200 bg-white dark:border-zinc-600 dark:bg-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-500'">
            <input type="radio" x-model="method" value="{{ $val }}" class="accent-[#7A00A3] shrink-0">
            <span class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ $lbl }}</span>
        </label>
    @endforeach
</div>
