<?php

namespace App\Livewire\SuperAdmin\Marketing\Templates;

use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Livewire\SuperAdmin\Marketing\Concerns\EditsEmailBlocks;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Services\Marketing\TemplateRenderer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** CRUD de template com editor de blocos e preview lado a lado. */
class Form extends Component
{
    use EditsEmailBlocks;
    use RequiresSuperAdmin;

    #[Locked]
    public ?int $templateId = null;

    public string $name = '';

    public string $subjectDefault = '';

    public string $preheaderDefault = '';

    public function mount(?EmailTemplate $template = null): void
    {
        if ($template?->exists) {
            $this->templateId = $template->id;
            $this->name = $template->name;
            $this->subjectDefault = (string) $template->subject_default;
            $this->preheaderDefault = (string) $template->preheader_default;
            $this->blocks = $template->blocks ?? [];

            return;
        }

        $this->blocks = [
            TemplateRenderer::newBlock('header'),
            TemplateRenderer::newBlock('text'),
            TemplateRenderer::newBlock('button'),
            TemplateRenderer::newBlock('footer'),
        ];
    }

    public function save(TemplateRenderer $renderer): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'subjectDefault' => ['nullable', 'string', 'max:200'],
            'preheaderDefault' => ['nullable', 'string', 'max:200'],
            ...$this->blockRules(),
        ], attributes: ['name' => 'nome', 'subjectDefault' => 'assunto padrão', 'preheaderDefault' => 'pré-cabeçalho padrão']);

        if ($unknown = $this->unknownBlockVariables($this->subjectDefault, $this->preheaderDefault)) {
            $this->addError('blocks', 'Variáveis desconhecidas: '.implode(', ', array_map(fn ($name) => '{{'.$name.'}}', $unknown)));

            return;
        }

        $blocks = $renderer->normalizeBlocks($this->blocks);
        $template = $this->templateId ? EmailTemplate::findOrFail($this->templateId) : new EmailTemplate(['created_by' => auth()->id()]);

        $template->fill([
            'name' => $this->name,
            'subject_default' => $this->subjectDefault ?: null,
            'preheader_default' => $this->preheaderDefault ?: null,
            'blocks' => $blocks,
            'html' => $renderer->blocksToHtml($blocks),
        ])->save();

        session()->flash('marketing_notice', 'Template salvo.');
        $this->redirectRoute('superadmin.marketing.templates.index', navigate: true);
    }

    #[Computed]
    public function previewCompanies()
    {
        return Company::withoutGlobalScopes()->where('active', true)->orderBy('name')->limit(200)->get(['id', 'name']);
    }

    protected function previewPreheader(): ?string
    {
        return $this->preheaderDefault;
    }

    public function render()
    {
        return view('livewire.super-admin.marketing.templates.form')
            ->layout('layouts.app', ['title' => 'Super Admin — Template de e-mail']);
    }
}
