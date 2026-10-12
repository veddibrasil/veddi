<dl class="space-y-2 border-l pl-3 text-sm dark:border-zinc-700">
    @foreach($data as $field => $value)
        <div>
            <dt class="font-medium break-words">
                {{ \App\Support\Ifood\IfoodFieldLabels::field($field) }}
                @if(\App\Support\Ifood\IfoodFieldLabels::original($field))
                    <span class="font-normal text-xs text-neutral-500 dark:text-neutral-400">({{ \App\Support\Ifood\IfoodFieldLabels::original($field) }})</span>
                @endif
            </dt>
            <dd class="break-words text-neutral-600 dark:text-neutral-300">
                @if(is_array($value))
                    @include('livewire.admin.settings.ifood-data', ['data' => $value])
                @else
                    {{ \App\Support\Ifood\IfoodFieldLabels::value($value) }}
                @endif
            </dd>
        </div>
    @endforeach
</dl>
