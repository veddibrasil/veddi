{{-- Preview do e-mail num iframe sem script nem mesma origem (sandbox vazio). --}}
<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="inline-flex rounded-lg border border-neutral-200 p-0.5 dark:border-zinc-600" role="group" aria-label="Tamanho do preview">
            @foreach(['desktop' => 'Desktop', 'mobile' => 'Celular'] as $device => $label)
                <button type="button" wire:click="setPreviewDevice('{{ $device }}')"
                    @class([
                        'px-3 py-1 text-xs font-medium rounded-md transition',
                        'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900' => $previewDevice === $device,
                        'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-zinc-700' => $previewDevice !== $device,
                    ])
                    aria-pressed="{{ $previewDevice === $device ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="w-full sm:w-64">
            <flux:select wire:model.live="previewCompanyId" size="sm" aria-label="Dados do preview">
                <flux:select.option value="">Dados de exemplo</flux:select.option>
                @foreach($previewCompanies as $company)
                    <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>
    <div class="rounded-xl border border-neutral-200 bg-neutral-100 p-3 dark:border-zinc-700 dark:bg-zinc-900 flex justify-center overflow-hidden">
        <iframe
            title="Preview do e-mail"
            sandbox=""
            srcdoc="{{ $this->previewHtml }}"
            class="bg-white rounded-lg shadow-sm transition-all {{ $previewDevice === 'mobile' ? 'w-[375px]' : 'w-full' }}"
            style="height: {{ $previewHeight ?? '640px' }}; max-width: 100%;"
        ></iframe>
    </div>
</div>
