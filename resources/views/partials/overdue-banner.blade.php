@auth
    @php
        $overdueCompany = app()->bound('current.company') ? app('current.company') : null;
    @endphp
    @if($overdueCompany?->isOverdue())
        @php
            $overdueDate = \Carbon\Carbon::parse($overdueCompany->overdue_since);
            $blockDate   = $overdueDate->copy();
            $added = 0;
            while ($added < 3) {
                $blockDate->addDay();
                if (! $blockDate->isWeekend()) $added++;
            }
            $daysLeft = (int) now()->startOfDay()->diffInDays($blockDate->startOfDay(), false);
        @endphp
        <div class="{{ $wrapperClass ?? '' }} shrink-0 bg-yellow-50 border-b border-yellow-300 px-4 py-3 text-sm text-yellow-800 flex items-start gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0 mt-0.5 text-yellow-500" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
            </svg>
            <span class="min-w-0 flex-1">
                <strong>Fatura em atraso.</strong>
                @if($daysLeft > 0)
                    O sistema será bloqueado em <strong>{{ $daysLeft }} {{ $daysLeft === 1 ? 'dia útil' : 'dias úteis' }}</strong>.
                @else
                    O sistema será bloqueado em breve.
                @endif
                <a href="{{ route('admin.billing') }}" class="underline font-medium whitespace-nowrap" wire:navigate>Regularizar agora</a>
            </span>
        </div>
    @endif
@endauth
