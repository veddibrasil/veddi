<?php

namespace App\Livewire\Admin\Fiscal;

use App\Models\Branch;
use App\Models\FiscalTemplate;
use App\Models\FiscalTemplateApplication;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Fiscal\FiscalTemplateService;
use App\Support\Fiscal\FiscalCorrectionLinks;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Tributação em lote: modelos fiscais da empresa e aplicação com prévia nos produtos
 * selecionados (cadastro geral ou perfil de uma filial). Gerente de filial só altera perfis
 * da própria filial. Aplicar nunca emite nota.
 */
class Templates extends Component
{
    // ── Modelo ─────────────────────────────────────────────────────────────
    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $crt = '';

    public string $state = '';

    public string $operation = 'Venda a consumidor final';

    public string $validFrom = '';

    public string $validUntil = '';

    public array $fields = [];

    public string $taxes = '';

    public string $notes = '';

    // ── Aplicação ──────────────────────────────────────────────────────────
    public string $applyTemplateId = '';

    /** 'general' ou id da filial. */
    public string $target = 'general';

    public string $mode = 'fill';

    public bool $confirmReplace = false;

    public string $categoryFilter = '';

    public string $search = '';

    /** Lista só produtos com campo da NF-e vazio no destino escolhido. */
    public bool $onlyPending = false;

    public array $selected = [];

    /** Caminho interno de onde o lojista veio (pedido ou Controle de notas), para voltar e emitir. */
    #[Locked]
    public ?string $returnTo = null;

    /** Prévia exibida — Locked: aplicar usa exatamente o que foi mostrado. */
    #[Locked]
    public ?array $preview = null;

    #[Locked]
    public array $previewParams = [];

    #[Locked]
    public ?int $lockedBranchId = null;

    public function mount(): void
    {
        $company = app('current.company');
        abort_unless($company->fiscal_notes_enabled && FiscalCorrectionLinks::canEditProducts(auth()->user(), $company), 403);

        $this->lockedBranchId = auth()->user()->lockedBranchId($company);
        if ($this->lockedBranchId) {
            $this->target = (string) $this->lockedBranchId;
        }

        // Atalho das pendências do pedido / Controle de notas: destino e produtos já escolhidos.
        // Nada vindo da URL é confiável: filial e produtos passam pelo escopo da empresa.
        $destination = request()->query('destino');
        if (! $this->lockedBranchId && is_string($destination) && ctype_digit($destination) && Branch::whereKey((int) $destination)->exists()) {
            $this->target = $destination;
        }
        $ids = is_string($products = request()->query('produtos')) ? array_filter(array_map('intval', explode(',', $products))) : [];
        if ($ids !== []) {
            $this->selected = $this->productsQuery(ignoreFilters: true)
                ->whereIn('id', array_slice(array_unique($ids), 0, FiscalTemplateService::MAX_PRODUCTS))
                ->pluck('id')->map(fn ($id) => (string) $id)->all();
        }
        $this->onlyPending = request()->boolean('pendentes');
        $this->returnTo = FiscalCorrectionLinks::safeReturnPath(request()->query('voltar'));

        // Com um modelo só, ele já vem escolhido para aplicar.
        $only = FiscalTemplate::orderBy('name')->limit(2)->pluck('id');
        if ($only->count() === 1) {
            $this->applyTemplateId = (string) $only->first();
        }
    }

    /** "Aplicar" na lista de modelos: escolhe o modelo na seção de aplicação. */
    public function useTemplate(int $id): void
    {
        $this->applyTemplateId = (string) FiscalTemplate::findOrFail($id)->id;
        $this->preview = null;
        $this->previewParams = [];
    }

    public function updated(string $property): void
    {
        // Qualquer mudança no que seria aplicado invalida a prévia.
        if (in_array($property, ['applyTemplateId', 'target', 'mode', 'confirmReplace'], true) || str_starts_with($property, 'selected')) {
            $this->preview = null;
            $this->previewParams = [];
        }
    }

    /** Modelo novo já vem com o regime e a UF das filiais, quando todas concordam. */
    public function newTemplate(FiscalTemplateService $service): void
    {
        $this->resetTemplateForm();
        $defaults = $service->companyDefaults(app('current.company')->id);
        $this->crt = (string) ($defaults['crt'] ?? '');
        $this->state = (string) ($defaults['state'] ?? '');
        $this->showForm = true;
    }

    /** Abre o formulário preenchido com uma sugestão; só vira modelo ao salvar. */
    public function useSuggestion(string $key, FiscalTemplateService $service): void
    {
        $this->authorizeTemplates();
        $suggestion = collect($service->suggestions(app('current.company')->id))->firstWhere('key', $key);
        abort_if($suggestion === null, 404);

        $this->resetTemplateForm();
        $this->name = $suggestion['name'];
        $this->crt = (string) ($suggestion['crt'] ?? '');
        $this->state = (string) ($suggestion['state'] ?? '');
        $this->fields = array_map('strval', array_intersect_key($suggestion['data'], FiscalTemplate::FIELDS));
        $this->taxes = empty($suggestion['data']['taxes']) ? '' : json_encode($suggestion['data']['taxes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->notes = $suggestion['notes'];
        $this->showForm = true;
    }

    public function editTemplate(int $id): void
    {
        $template = FiscalTemplate::findOrFail($id);
        $this->resetTemplateForm();
        $this->editingId = $template->id;
        $this->name = $template->name;
        $this->crt = (string) ($template->crt ?? '');
        $this->state = (string) ($template->state ?? '');
        $this->operation = (string) ($template->operation ?? '');
        $this->validFrom = $template->valid_from?->toDateString() ?? '';
        $this->validUntil = $template->valid_until?->toDateString() ?? '';
        $this->fields = array_map('strval', array_intersect_key($template->data ?? [], FiscalTemplate::FIELDS));
        $this->taxes = empty($template->data['taxes']) ? '' : json_encode($template->data['taxes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->notes = (string) ($template->notes ?? '');
        $this->showForm = true;
    }

    public function saveTemplate(FiscalTemplateService $service): void
    {
        $this->authorizeTemplates();
        $companyId = app('current.company')->id;
        $this->state = mb_strtoupper(trim($this->state));
        $this->validate([
            'name' => ['required', 'string', 'max:80', \Illuminate\Validation\Rule::unique('fiscal_templates', 'name')->where('company_id', $companyId)->ignore($this->editingId)],
            'crt' => ['nullable', 'in:1,2,3'],
            'state' => ['nullable', 'in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO'],
            'operation' => ['nullable', 'string', 'max:80'],
            'validFrom' => ['nullable', 'date'],
            'validUntil' => ['nullable', 'date', 'after_or_equal:validFrom'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['name' => 'nome', 'validUntil' => 'fim da vigência', 'validFrom' => 'início da vigência', 'state' => 'UF']);

        try {
            $data = $service->validateTemplateData($this->fields, $this->taxes, $this->crt !== '' ? (int) $this->crt : null);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => [
                in_array($field, ['taxes', 'fields'], true) ? $field : 'fields.'.$field => $messages,
            ])->all());
        }

        $attributes = [
            'name' => trim($this->name),
            'crt' => $this->crt !== '' ? (int) $this->crt : null,
            'state' => $this->state ?: null,
            'operation' => trim($this->operation) ?: null,
            'valid_from' => $this->validFrom ?: null,
            'valid_until' => $this->validUntil ?: null,
            'data' => $data,
            'notes' => trim($this->notes) ?: null,
            'updated_by' => auth()->id(),
        ];
        if ($this->editingId) {
            $template = FiscalTemplate::findOrFail($this->editingId);
            $template->update($attributes);
        } else {
            $template = FiscalTemplate::create([...$attributes, 'created_by' => auth()->id()]);
        }

        // O modelo salvo já fica escolhido em "Aplicar modelo".
        $this->applyTemplateId = (string) $template->id;
        $this->showForm = false;
        $this->preview = null;
        $this->previewParams = [];
        session()->flash('status', 'Modelo fiscal salvo. Produtos só mudam quando o modelo for aplicado.');
    }

    public function deleteTemplate(int $id): void
    {
        $this->authorizeTemplates();
        FiscalTemplate::findOrFail($id)->delete();
        if ($this->applyTemplateId === (string) $id) {
            $this->applyTemplateId = '';
            $this->preview = null;
        }
        session()->flash('status', 'Modelo excluído. Produtos já alterados continuam como estão.');
    }

    public function selectFiltered(): void
    {
        $this->selected = $this->productsQuery()->limit(FiscalTemplateService::MAX_PRODUCTS)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->preview = null;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->preview = null;
    }

    public function generatePreview(FiscalTemplateService $service): void
    {
        $params = $this->applyParams();
        $template = FiscalTemplate::findOrFail($params['template_id']);
        $this->preview = $service->preview($template, $params['product_ids'], $params['branch_id'], $params['mode']);
        $this->previewParams = $params;
    }

    public function discardPreview(): void
    {
        $this->preview = null;
        $this->previewParams = [];
    }

    public function applyPreview(FiscalTemplateService $service): void
    {
        $this->authorizeManage();
        abort_if($this->preview === null || $this->previewParams === [], 422);
        $params = $this->previewParams;
        // Branch manager: a filial travada é revalidada aqui, não só na tela.
        abort_if($this->lockedBranchId && $params['branch_id'] !== $this->lockedBranchId, 403);

        $fingerprints = collect($this->preview['rows'])->filter(fn ($row) => $row['fingerprint'])->mapWithKeys(fn ($row) => [$row['product_id'] => $row['fingerprint']])->all();

        try {
            $application = $service->apply(FiscalTemplate::findOrFail($params['template_id']), $params['product_ids'], $params['branch_id'], $params['mode'], $fingerprints, auth()->user(), $this->preview['template_fingerprint'] ?? null);
        } catch (\RuntimeException $e) {
            $this->addError('apply', $e->getMessage());

            return;
        }

        $this->preview = null;
        $this->previewParams = [];
        $this->selected = [];
        session()->flash('status', "Modelo aplicado em {$application->products_count} produto(s). Nenhuma nota foi emitida; confira os pedidos pendentes no Controle de notas.");
    }

    public function revert(int $applicationId, FiscalTemplateService $service): void
    {
        $this->authorizeManage();
        $application = FiscalTemplateApplication::findOrFail($applicationId);
        abort_if($this->lockedBranchId && $application->branch_id !== $this->lockedBranchId, 403);

        try {
            $result = $service->revert($application, auth()->user());
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('status', "Aplicação desfeita em {$result['restored']} produto(s)."
            .($result['skipped'] !== [] ? ' Mantidos por terem sido alterados depois: '.implode(', ', array_slice($result['skipped'], 0, 10)).'.' : ''));
    }

    public function render()
    {
        return view('livewire.admin.fiscal.templates', [
            'templates' => $templates = FiscalTemplate::orderBy('name')->get(),
            // Gerente de filial não cria modelos: sem sugestões para ele.
            'suggestions' => $this->lockedBranchId ? [] : app(FiscalTemplateService::class)->suggestions(app('current.company')->id),
            'applyTemplate' => $this->applyTemplateId !== '' ? $templates->firstWhere('id', (int) $this->applyTemplateId) : null,
            'branches' => $this->lockedBranchId ? Branch::whereKey($this->lockedBranchId)->get(['id', 'name']) : Branch::orderBy('name')->get(['id', 'name']),
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
            'products' => $products = $this->productsQuery()->with('category:id,name')->limit(FiscalTemplateService::MAX_PRODUCTS)->get(['id', 'name', 'product_category_id', 'fiscal_data']),
            'productStatus' => app(FiscalTemplateService::class)->listStatus(
                app('current.company')->id,
                $products->pluck('id')->all(),
                $this->lockedBranchId ?: ($this->target !== 'general' ? (int) $this->target : null),
            ),
            'applications' => FiscalTemplateApplication::with(['user:id,name', 'branch:id,name'])
                ->when($this->lockedBranchId, fn ($q) => $q->where('branch_id', $this->lockedBranchId))
                ->latest('id')->limit(20)->get(),
            'fieldLabels' => FiscalTemplate::FIELDS,
        ])->layout('layouts.app', ['title' => 'Tributação em lote']);
    }

    /** @return array{template_id: int, product_ids: array<int, int>, branch_id: ?int, mode: string} */
    private function applyParams(): array
    {
        $this->validate([
            'applyTemplateId' => ['required', 'integer'],
            'target' => ['required'],
            'mode' => ['required', 'in:fill,replace'],
            'selected' => ['required', 'array', 'min:1', 'max:'.FiscalTemplateService::MAX_PRODUCTS],
        ], ['selected.required' => 'Selecione ao menos um produto.', 'applyTemplateId.required' => 'Escolha o modelo.']);
        if ($this->mode === 'replace' && ! $this->confirmReplace) {
            throw ValidationException::withMessages(['confirmReplace' => 'Confirme que deseja substituir valores já preenchidos.']);
        }
        $branchId = $this->target === 'general' ? null : (int) $this->target;
        if ($this->lockedBranchId && $branchId !== $this->lockedBranchId) {
            throw ValidationException::withMessages(['target' => 'Você só pode alterar o perfil fiscal da sua filial.']);
        }
        if ($branchId && ! Branch::whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['target' => 'Filial inválida.']);
        }
        FiscalTemplate::findOrFail((int) $this->applyTemplateId);

        // Ids da tela nunca são confiáveis: só produtos desta empresa (escopo) e, para gerente, da filial dele.
        $ids = $this->productsQuery(ignoreFilters: true)->whereIn('id', array_map('intval', $this->selected))->pluck('id')->all();

        return ['template_id' => (int) $this->applyTemplateId, 'product_ids' => $ids, 'branch_id' => $branchId, 'mode' => $this->mode];
    }

    private function productsQuery(bool $ignoreFilters = false)
    {
        $branchId = $this->lockedBranchId ?: ($this->target !== 'general' ? (int) $this->target : null);

        return Product::query()
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->when(! $ignoreFilters && $this->categoryFilter !== '', fn ($q) => $q->where('product_category_id', (int) $this->categoryFilter))
            ->when(! $ignoreFilters && trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%'))
            ->when(! $ignoreFilters && $this->onlyPending, fn ($q) => $q->whereIn('id', $this->pendingProductIds($branchId)))
            ->orderBy('name');
    }

    /** @return array<int, int> produtos da empresa com campo da NF-e vazio no destino */
    private function pendingProductIds(?int $branchId): array
    {
        $ids = Product::query()
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->pluck('id')->all();

        return app(FiscalTemplateService::class)->incompleteProductIds(app('current.company')->id, $ids, $branchId);
    }

    private function authorizeManage(): void
    {
        abort_unless(FiscalCorrectionLinks::canEditProducts(auth()->user(), app('current.company')), 403);
    }

    /** Modelos valem para a empresa inteira: só quem não está preso a uma filial cria ou exclui. */
    private function authorizeTemplates(): void
    {
        $this->authorizeManage();
        abort_if($this->lockedBranchId !== null, 403);
    }

    private function resetTemplateForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->name = '';
        $this->crt = '';
        $this->state = '';
        $this->operation = 'Venda a consumidor final';
        $this->validFrom = '';
        $this->validUntil = '';
        $this->fields = [];
        $this->taxes = '';
        $this->notes = '';
    }
}
