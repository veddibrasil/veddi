<?php

namespace App\Livewire\Admin;

use Illuminate\View\View;
use Livewire\Component;

class SetupChecklist extends Component
{
    /** @var array<int, array{done: bool, title: string, description: string, actionLabel: string, actionRoute: string, helpUrl?: string, helpLabel?: string, descriptionSteps?: array<int, string>}> */
    public array $steps = [];

    public int $doneCount = 0;

    public function mount(): void
    {
        $company = app('current.company');

        $this->steps = [
            [
                'done' => ! empty($company->logo_path),
                'title' => 'Adicionar logo da empresa',
                'description' => 'Personalize sua loja com a logo da sua empresa.',
                'actionLabel' => 'Adicionar logo',
                'actionRoute' => route('admin.settings'),
            ],
            [
                'done' => $company->branches()->exists(),
                'title' => 'Cadastrar uma filial',
                'description' => 'Adicione pelo menos uma filial para receber pedidos.',
                'actionLabel' => 'Criar filial',
                'actionRoute' => route('admin.branches.index'),
            ],
            [
                'done' => ! empty($company->email),
                'title' => 'Adicionar o email da empresa cadastrado na Vindi',
                'description' => 'Siga os passos abaixo para liberar os pagamentos da sua empresa.',
                'descriptionSteps' => [
                    'Acesse pagamentos.vindi.com.br/criar-conta-v2 e crie sua conta.',
                    'Copie o email usado no cadastro.',
                    'Informe esse email na tela de configurações da empresa.',
                ],
                'actionLabel' => 'Adicionar email',
                'actionRoute' => route('admin.settings'),
                'helpUrl' => 'https://pagamentos.vindi.com.br/criar-conta-v2/',
                'helpLabel' => 'Criar conta na Vindi',
            ],
            [
                'done' => $company->productCategories()->exists(),
                'title' => 'Criar uma categoria',
                'description' => 'Organize seu cardápio criando categorias de produtos.',
                'actionLabel' => 'Criar categoria',
                'actionRoute' => route('admin.categories.index'),
            ],
            [
                'done' => $company->products()->exists(),
                'title' => 'Adicionar um produto',
                'description' => 'Cadastre os produtos que aparecem no seu cardápio.',
                'actionLabel' => 'Criar produto',
                'actionRoute' => route('admin.products.index'),
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
