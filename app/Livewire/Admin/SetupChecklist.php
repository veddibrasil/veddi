<?php

namespace App\Livewire\Admin;

use Illuminate\View\View;
use Livewire\Component;

class SetupChecklist extends Component
{
    /** @var array<int, array{done: bool, title: string, description: string, actionLabel: string, actionRoute: string}> */
    public array $steps = [];

    public int $doneCount = 0;

    public function mount(): void
    {
        $company = app('current.company');

        $this->steps = [
            [
                'done'        => ! empty($company->logo_path),
                'title'       => 'Adicionar logo da empresa',
                'description' => 'Personalize sua loja com a logo da sua empresa.',
                'actionLabel' => 'Adicionar logo',
                'actionRoute' => route('admin.settings'),
            ],
        ];

        $this->doneCount = count(array_filter($this->steps, fn ($s) => $s['done']));
    }

    public function isComplete(): bool
    {
        return $this->doneCount === count($this->steps);
    }

    public function render(): View
    {
        return view('livewire.admin.setup-checklist');
    }
}
