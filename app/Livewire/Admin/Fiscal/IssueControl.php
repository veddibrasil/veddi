<?php

namespace App\Livewire\Admin\Fiscal;

use App\Enums\OrderChannel;
use App\Jobs\IssueFiscalNote;
use App\Models\Branch;
use App\Models\CompanyNotification;
use App\Models\Order;
use App\Services\Fiscal\FiscalNoteService;
use App\Services\Fiscal\FiscalRecipient;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Controle de notas fiscais: lista pedidos pagos com a situação fiscal de cada um. A emissão
 * deixou de ser automática — o que o caixa não emitiu no PDV ("Emitir e imprimir nota fiscal")
 * fica aqui "sem nota" e pode ser emitido individualmente ou em lote (Bus::batch na fila 'low').
 */
class IssueControl extends Component
{
    use WithPagination;

    /** Teto por lote — protege a fila e a Focus NFe de um "selecionar tudo" de meses. */
    public const MAX_BATCH = 200;

    private const FILTERS = ['dateFrom', 'dateTo', 'branchFilter', 'channelFilter', 'paymentFilter', 'situationFilter', 'search'];

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public string $branchFilter = '';

    #[Url]
    public string $channelFilter = '';

    #[Url]
    public string $paymentFilter = '';

    #[Url]
    public string $situationFilter = 'to_issue';

    #[Url]
    public string $search = '';

    /** Ids marcados na tela — nunca confiáveis: issue/prepare refazem a consulta com escopo. */
    public array $selected = [];

    public bool $showConfirmModal = false;

    /** Resumo do lote montado em prepareIssue() pro modal de confirmação. */
    public array $confirmSummary = [];

    /** Pedidos que passaram na pré-checagem — Locked pro navegador não trocar a lista entre o resumo e a confirmação. */
    #[Locked]
    public array $readyOrderIds = [];

    #[Locked]
    public ?string $batchId = null;

    public bool $showIssueOneModal = false;

    /** Pedido aberto no modal da linha — Locked pelo mesmo motivo de readyOrderIds. */
    #[Locked]
    public ?int $issueOneOrderId = null;

    /** Pendência da pré-checagem do pedido do modal; null = pronto pra emitir. */
    #[Locked]
    public ?string $issueOneBlockedReason = null;

    #[Locked]
    public bool $canIssue = false;

    /** branch_manager fica preso à própria filial (mesmo bind de CheckCompanyRole). */
    #[Locked]
    public ?int $lockedBranchId = null;

    public function mount(): void
    {
        $company = app('current.company');
        $user = auth()->user();

        abort_unless($company->fiscal_notes_enabled, 403, 'Módulo de nota fiscal não está habilitado para esta empresa.');
        abort_unless($user->hasPermission('fiscal.view', $company), 403);

        $this->canIssue = $user->hasPermission('fiscal.issue', $company);

        if (app()->bound('current.branch')) {
            $this->lockedBranchId = app('current.branch')->id;
        }

        if ($this->dateFrom === '' && $this->dateTo === '') {
            $this->dateFrom = $this->dateTo = now()->toDateString();
        }

        $this->batchId = session('fiscal.issue_batch_id');
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function selectPage(): void
    {
        $ids = $this->filteredQuery()
            ->with('latestFiscalNote')
            ->latest('id')
            ->paginate(25)
            ->getCollection()
            ->filter(fn (Order $order) => $this->isSelectable($order))
            ->pluck('id');

        $this->selected = $this->normalizedSelection()->merge($ids)->unique()->take(self::MAX_BATCH)->values()->all();
    }

    public function selectAllFiltered(): void
    {
        $this->selected = $this->filteredQuery()
            ->where(fn (Builder $q) => $this->whereSelectable($q))
            ->latest('id')
            ->limit(self::MAX_BATCH)
            ->pluck('id')
            ->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /** Pré-checagem do lote: separa o que pode ser emitido do que tem pendência e abre a confirmação. */
    public function prepareIssue(): void
    {
        $this->authorizeIssue();

        $orders = $this->eligibleOrders($this->normalizedSelection()->take(self::MAX_BATCH)->all());

        if ($orders->isEmpty()) {
            session()->flash('error', 'Nenhum pedido selecionado pode ser emitido (já tem nota ativa ou não está pago).');

            return;
        }

        $service = app(FiscalNoteService::class);
        $ready = collect();
        $blocked = [];

        foreach ($orders as $order) {
            $reason = $service->preflight($order);

            if ($reason === null) {
                $ready->push($order);
            } else {
                $blocked[] = ['order_number' => $order->order_number, 'reason' => $reason];
            }
        }

        $this->readyOrderIds = $ready->pluck('id')->all();
        $this->confirmSummary = [
            'ready' => $ready->count(),
            'total' => (float) $ready->sum('total'),
            'nfe' => $ready->filter(fn (Order $o) => $this->predictedModel($o) === '55')->count(),
            'nfce' => $ready->filter(fn (Order $o) => $this->predictedModel($o) === '65')->count(),
            'older' => $ready->filter(fn (Order $o) => $o->created_at->lt(today()))->count(),
            'blocked' => $blocked,
        ];
        $this->showConfirmModal = true;
    }

    public function closeConfirmModal(): void
    {
        $this->showConfirmModal = false;
        $this->readyOrderIds = [];
        $this->confirmSummary = [];
    }

    public function issueSelected(): void
    {
        $this->authorizeIssue();

        // Refaz a elegibilidade: entre o resumo e a confirmação alguém pode ter emitido pelo
        // pedido ou pelo PDV. FiscalNoteService::issue() ainda trava o pedido no job.
        $ids = $this->eligibleOrders($this->readyOrderIds)->pluck('id');

        if ($ids->isEmpty()) {
            $this->closeConfirmModal();
            session()->flash('error', 'Os pedidos selecionados já foram emitidos ou não estão mais disponíveis.');

            return;
        }

        $companyId = app('current.company')->id;
        $userId = auth()->id();
        $orderIds = $ids->all();
        $link = route('admin.fiscal.control', ['situationFilter' => 'failed']);

        $batch = Bus::batch($ids->map(fn (int $id) => new IssueFiscalNote($id, null, $userId))->all())
            ->name('fiscal-issue:company-'.$companyId)
            ->onQueue('low')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($companyId, $orderIds, $link) {
                $outcome = app(FiscalNoteService::class)->batchOutcome($companyId, $orderIds);
                $problems = $outcome['rejected'] + $outcome['not_sent'];

                CompanyNotification::create([
                    'company_id' => $companyId,
                    'type' => $problems > 0 ? 'fiscal_note_failed' : 'fiscal_batch_finished',
                    'title' => 'Emissão de notas em lote concluída',
                    'subtitle' => FiscalNoteService::describeBatchOutcome($outcome).'.'.($problems > 0 ? ' Confira em "Com erro" no Controle de notas.' : ''),
                    'link' => $link,
                ]);
            })
            ->dispatch();

        Log::channel('fiscal')->info('IssueControl: emissão em lote enfileirada', [
            'company_id' => $companyId,
            'batch_id' => $batch->id,
            'orders' => $ids->count(),
        ]);

        $this->batchId = $batch->id;
        session(['fiscal.issue_batch_id' => $batch->id, 'fiscal.issue_batch_orders' => $orderIds]);
        $this->selected = [];
        $this->closeConfirmModal();
    }

    /** Confirmação de um pedido só, aberta pela linha: mostra a última falha e a pré-checagem antes de emitir. */
    public function openIssueOne(int $orderId): void
    {
        $this->authorizeIssue();

        $order = $this->eligibleOrders([$orderId])->first();

        if (! $order) {
            session()->flash('error', 'Pedido já tem nota ativa ou não pode ser emitido.');

            return;
        }

        $this->issueOneOrderId = $order->id;
        $this->issueOneBlockedReason = app(FiscalNoteService::class)->preflight($order);
        $this->showIssueOneModal = true;
    }

    public function closeIssueOneModal(): void
    {
        $this->showIssueOneModal = false;
        $this->issueOneOrderId = null;
        $this->issueOneBlockedReason = null;
    }

    /** Emissão do pedido confirmado no modal — fila 'default', igual ao botão do pedido. */
    public function issueOne(): void
    {
        $this->authorizeIssue();

        // Refaz elegibilidade e pré-checagem: entre abrir o modal e confirmar o pedido pode ter
        // sido emitido pelo PDV ou a config fiscal pode ter mudado.
        $order = $this->issueOneOrderId ? $this->eligibleOrders([$this->issueOneOrderId])->first() : null;
        $this->closeIssueOneModal();

        if (! $order) {
            session()->flash('error', 'Pedido já tem nota ativa ou não pode ser emitido.');

            return;
        }

        if ($reason = app(FiscalNoteService::class)->preflight($order)) {
            session()->flash('error', "Pedido {$order->order_number}: {$reason}");

            return;
        }

        IssueFiscalNote::dispatch($order->id, null, auth()->id());
        session()->flash('status', "Emissão da nota do pedido {$order->order_number} enfileirada.");
    }

    public function dismissBatch(): void
    {
        $this->batchId = null;
        session()->forget(['fiscal.issue_batch_id', 'fiscal.issue_batch_orders']);
    }

    public function render()
    {
        $orders = $this->filteredQuery()
            ->with(['customer', 'branch', 'latestFiscalNote'])
            ->latest('id')
            ->paginate(25);

        $batch = $this->batchId ? Bus::findBatch($this->batchId) : null;

        // Lote de outra empresa (sessão compartilhada entre empresas do mesmo usuário) não aparece.
        if ($batch && $batch->name !== 'fiscal-issue:company-'.app('current.company')->id) {
            $batch = null;
        }

        $batchOutcome = $batch?->finished()
            ? app(FiscalNoteService::class)->batchOutcome(app('current.company')->id, session('fiscal.issue_batch_orders', []))
            : null;

        $issueOneOrder = $this->showIssueOneModal && $this->issueOneOrderId
            ? $this->eligibleOrders([$this->issueOneOrderId])->first()?->loadMissing('latestFiscalNote')
            : null;

        return view('livewire.admin.fiscal.issue-control', [
            'orders' => $orders,
            'batchOutcome' => $batchOutcome,
            'selectable' => $orders->getCollection()->mapWithKeys(fn (Order $o) => [$o->id => $this->isSelectable($o)]),
            'models' => $orders->getCollection()->mapWithKeys(fn (Order $o) => [$o->id => $this->predictedModel($o)]),
            'channels' => $orders->getCollection()->mapWithKeys(fn (Order $o) => [$o->id => $this->channelLabel($o)]),
            'branches' => $this->lockedBranchId ? collect() : Branch::orderBy('name')->get(['id', 'name']),
            'batch' => $batch,
            'issueOneOrder' => $issueOneOrder,
            'issueOneModel' => $issueOneOrder ? $this->predictedModel($issueOneOrder) : null,
            'issueOneChannel' => $issueOneOrder ? $this->channelLabel($issueOneOrder) : null,
        ])->layout('layouts.app', ['title' => 'Controle de notas fiscais']);
    }

    private function isSelectable(Order $order): bool
    {
        return ! in_array($order->latestFiscalNote?->status, ['pending', 'authorized'], true);
    }

    private function predictedModel(Order $order): string
    {
        return $order->latestFiscalNote?->model
            ?? FiscalRecipient::model($order->fiscal_recipient ?? ['document' => '']);
    }

    private function channelLabel(Order $order): string
    {
        return match (true) {
            $order->isMarketplaceOrder() => OrderChannel::from($order->channel)->label(),
            $order->order_type === 'pdv' && $order->table_label !== null => 'Comanda · '.$order->table_label,
            $order->order_type === 'pdv' => 'Balcão',
            default => 'Chat',
        };
    }

    private function authorizeIssue(): void
    {
        $company = app('current.company');

        abort_unless($this->canIssue && $company->canUseFiscalNotes() && auth()->user()->hasPermission('fiscal.issue', $company), 403);
    }

    /** @return Collection<int, int> */
    private function normalizedSelection(): Collection
    {
        return collect($this->selected)->map(fn ($id) => (int) $id)->filter()->unique()->values();
    }

    /**
     * Pedidos da empresa atual (escopo global), da filial travada, pagos e sem nota ativa.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, Order>
     */
    private function eligibleOrders(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Order::query()
            ->whereKey(array_map('intval', $ids))
            ->whereIn('status', Order::FISCAL_ISSUABLE_STATUSES)
            ->when($this->lockedBranchId, fn (Builder $q) => $q->where('branch_id', $this->lockedBranchId))
            ->whereDoesntHave('fiscalNotes', fn (Builder $q) => $q->whereIn('status', ['pending', 'authorized']))
            ->with(['company', 'customer', 'branch', 'items.product'])
            ->get();
    }

    private function filteredQuery(): Builder
    {
        $from = $this->parseDate($this->dateFrom)?->startOfDay();
        $to = $this->parseDate($this->dateTo)?->endOfDay();
        $search = trim($this->search);

        return Order::query()
            ->whereIn('status', Order::FISCAL_ISSUABLE_STATUSES)
            ->when($this->lockedBranchId, fn (Builder $q) => $q->where('branch_id', $this->lockedBranchId))
            ->when(! $this->lockedBranchId && $this->branchFilter !== '', fn (Builder $q) => $q->where('branch_id', (int) $this->branchFilter))
            ->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('created_at', '<=', $to))
            ->when($this->channelFilter !== '', fn (Builder $q) => $this->applyChannelFilter($q))
            ->when($this->paymentFilter !== '', fn (Builder $q) => $this->applyPaymentFilter($q))
            ->when($this->situationFilter !== '' && $this->situationFilter !== 'all', fn (Builder $q) => $this->applySituationFilter($q))
            ->when($search !== '', function (Builder $q) use ($search) {
                $digits = preg_replace('/\D/', '', $search);

                $q->where(function (Builder $q) use ($search, $digits) {
                    $q->where('order_number', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', '%'.$search.'%'));

                    if (strlen($digits) >= 3) {
                        $q->orWhere('fiscal_recipient->document', 'like', '%'.$digits.'%');
                    }
                });
            });
    }

    private function applyChannelFilter(Builder $query): void
    {
        match ($this->channelFilter) {
            'balcao' => $query->where('order_type', 'pdv')->whereNull('table_label'),
            'comanda' => $query->where('order_type', 'pdv')->whereNotNull('table_label'),
            'chat' => $query->where('order_type', '!=', 'pdv')->whereNotIn('channel', OrderChannel::marketplaceValues()),
            'ifood', '99food' => $query->where('channel', $this->channelFilter),
            default => null,
        };
    }

    /** payment_method não é uniforme entre canais (PDV grava minúsculo, chat maiúsculo). */
    private function applyPaymentFilter(Builder $query): void
    {
        $methods = match ($this->paymentFilter) {
            'pix' => ['pix'],
            'card' => ['credit_card', 'card', 'debit_card'],
            'cash' => ['cash'],
            'split' => ['split'],
            default => null,
        };

        if ($methods) {
            $query->whereIn(DB::raw('LOWER(payment_method)'), $methods);
        }
    }

    private function applySituationFilter(Builder $query): void
    {
        $withLatest = fn (array $statuses) => fn (Builder $q) => $q->whereHas('latestFiscalNote', fn (Builder $n) => $n->whereIn('status', $statuses));

        match ($this->situationFilter) {
            'to_issue' => $query->where(fn (Builder $q) => $q->whereDoesntHave('fiscalNotes')->orWhere($withLatest(['error', 'rejected']))),
            'none' => $query->whereDoesntHave('fiscalNotes')->whereNull('fiscal_error'),
            'failed' => $query->where(fn (Builder $q) => $q->where($withLatest(['error', 'rejected']))
                ->orWhere(fn (Builder $q) => $q->whereDoesntHave('fiscalNotes')->whereNotNull('fiscal_error'))),
            'pending', 'authorized', 'cancelled' => $query->where($withLatest([$this->situationFilter])),
            default => null,
        };
    }

    /** Mesmo critério de isSelectable() em SQL: sem nota, ou última tentativa fora de pending/authorized. */
    private function whereSelectable(Builder $query): void
    {
        $query->whereDoesntHave('fiscalNotes', fn (Builder $q) => $q->whereIn('status', ['pending', 'authorized']));
    }

    private function parseDate(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
