<div @if($this->isComplete()) style="display:none" @endif class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">Configure sua conta</h2>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">
                {{ $doneCount }} de {{ count($steps) }} {{ count($steps) === 1 ? 'passo concluído' : 'passos concluídos' }}
            </p>
        </div>
        <span class="text-sm font-semibold text-purple-600 dark:text-purple-400">
            {{ round(($doneCount / count($steps)) * 100) }}%
        </span>
    </div>

    {{-- Barra de progresso --}}
    <div class="w-full h-1.5 bg-neutral-100 dark:bg-zinc-700 rounded-full mb-5">
        <div
            class="h-1.5 rounded-full bg-purple-500 transition-all duration-500"
            style="width: {{ round(($doneCount / count($steps)) * 100) }}%"
        ></div>
    </div>

    {{-- Lista de passos --}}
    <ul class="space-y-4">
        @foreach($steps as $step)
            <li class="flex items-start gap-4">
                {{-- Ícone de status --}}
                @if($step['done'])
                    <span class="mt-0.5 flex-shrink-0 w-5 h-5 rounded-full bg-green-100 dark:bg-green-900/40 flex items-center justify-center">
                        <svg class="w-3 h-3 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                        </svg>
                    </span>
                @else
                    <span class="mt-0.5 flex-shrink-0 w-5 h-5 rounded-full border-2 border-neutral-300 dark:border-zinc-500"></span>
                @endif

                {{-- Conteúdo --}}
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium {{ $step['done'] ? 'text-neutral-400 dark:text-neutral-500 line-through' : 'text-neutral-800 dark:text-neutral-100' }}">
                        {{ $step['title'] }}
                    </p>
                    @if(!$step['done'])
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">{{ $step['description'] }}</p>
                        @if(!empty($step['descriptionSteps']))
                            <ol class="mt-1 grid grid-cols-1 gap-0.5 text-xs text-neutral-500 dark:text-neutral-400 list-decimal list-inside">
                                @foreach($step['descriptionSteps'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ol>
                        @endif
                        @if(!empty($step['helpUrl']))
                            <a href="{{ $step['helpUrl'] }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="inline-block text-xs font-semibold text-purple-600 hover:text-purple-700 dark:text-purple-400 dark:hover:text-purple-300 underline underline-offset-2 mt-1">
                                {{ $step['helpLabel'] ?? $step['helpUrl'] }} ↗
                            </a>
                        @endif
                    @endif
                </div>

                {{-- Botão de ação --}}
                @if(!$step['done'])
                    <a href="{{ $step['actionRoute'] }}"
                       wire:navigate
                       class="flex-shrink-0 text-xs font-semibold text-purple-600 hover:text-purple-700 dark:text-purple-400 dark:hover:text-purple-300 whitespace-nowrap underline underline-offset-2">
                        {{ $step['actionLabel'] }} →
                    </a>
                @endif
            </li>
        @endforeach
    </ul>
</div>
