<?php

namespace App\Services\Food99;

use App\Contracts\Food99EventHandler;
use App\Contracts\Food99GatewayContract;
use App\Models\CompanyNotification;
use App\Models\Food99Integration;
use App\Models\Food99WebhookEvent;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cardápio V3 da 99Food. O upload sobrescreve o cardápio inteiro e é assíncrono (resultado no
 * callback uploadMenuTaskStatus); disponibilidade muda em tempo real por updateItemStatus.
 *
 * Ids determinísticos, sem tabela de mapeamento: item "veddi-p{product}", complemento
 * "veddi-o{option}", grupo "veddi-g{group}", categoria "veddi-c{category}", menu "veddi-m{branch}".
 * Complementos também são items (is_sold_separately = false) referenciados pelos grupos.
 */
class Food99CatalogSyncService implements Food99EventHandler
{
    public const MAX_CATEGORIES = 20;

    public const MAX_MAIN_ITEMS = 400;

    public const MAX_ITEMS = 4000;

    public const MAX_GROUPS = 500;

    private const TASK_STATUSES = [0 => 'running', 1 => 'success', 2 => 'failed', 3 => 'running', 4 => 'running', 5 => 'partial'];

    public function __construct(private readonly Food99GatewayContract $gateway) {}

    public function handles(string $type): bool
    {
        return in_array($type, ['uploadMenuTaskStatus', 'imageAuditStatus'], true);
    }

    public function handle(Food99WebhookEvent $event): bool
    {
        $integration = $event->food99Integration;
        if (! $integration) {
            return false;
        }

        $data = $event->data();

        if ($event->event_type === 'imageAuditStatus') {
            return $this->applyImageAudit($integration, $data);
        }

        return $this->applyTaskResult($integration, (string) ($data['taskID'] ?? $data['task_id'] ?? ''), (int) ($data['status'] ?? -1), (array) ($data['operationList'] ?? []));
    }

    /**
     * Monta o cardápio da filial e a lista de pendências. Itens com problema ficam fora sem
     * travar o resto (a pendência aparece na aba Cardápio e no cadastro do produto).
     *
     * @return array{menu: array, issues: array<int, string>, warnings: array<int, string>, products: array<int, int>}
     */
    public function buildMenu(Food99Integration $integration): array
    {
        $products = $this->sellableProducts($integration);
        $availability = $this->availability($integration->branch_id, $products->modelKeys());
        $issues = $warnings = [];

        $categories = $products->filter(function (Product $product) use (&$issues) {
            if (! $product->category) {
                $issues[$product->id] = 'Produto sem categoria. Escolha uma categoria no cadastro.';

                return false;
            }
            if (mb_strlen($product->name) > 180) {
                $issues[$product->id] = 'Nome com mais de 180 caracteres (limite da 99Food).';

                return false;
            }
            if (mb_strlen($product->category->name) > 100) {
                $issues[$product->id] = 'Nome da categoria com mais de 100 caracteres (limite da 99Food).';

                return false;
            }

            return true;
        })->groupBy('product_category_id')->sortBy(fn (Collection $items) => [$items->first()->category->sort_order, $items->first()->category->id]);

        if ($categories->count() > self::MAX_CATEGORIES) {
            foreach ($categories->slice(self::MAX_CATEGORIES)->flatten() as $product) {
                $issues[$product->id] = 'A 99Food aceita até '.self::MAX_CATEGORIES.' categorias. Esta ficou de fora.';
            }
            $categories = $categories->take(self::MAX_CATEGORIES);
        }

        $menuCategories = $items = $groups = $included = [];

        foreach ($categories as $categoryProducts) {
            $category = $categoryProducts->first()->category;
            $itemIds = [];

            foreach ($categoryProducts->sortBy(['sort_order', 'id']) as $product) {
                if (count($included) >= self::MAX_MAIN_ITEMS) {
                    $issues[$product->id] = 'A 99Food aceita até '.self::MAX_MAIN_ITEMS.' itens principais. Este ficou de fora.';

                    continue;
                }

                $groupIds = [];
                foreach ($product->optionGroups as $group) {
                    // Grupo fixo já está no preço do produto: não vira escolha na 99.
                    if ($group->fixed || $group->options->isEmpty()) {
                        continue;
                    }
                    $groupIds[] = $this->groupId($group->id);
                    if (! isset($groups[$group->id])) {
                        $groups[$group->id] = $this->groupPayload($group, $items);
                    }
                }

                [$head, $warning] = $this->image($product->image_path ? $product->image_url : null);
                if ($warning) {
                    $warnings[$product->id] = $warning;
                }

                $items[$this->itemId($product->id)] = array_filter([
                    'app_item_id' => $this->itemId($product->id),
                    'item_name' => $product->name,
                    'short_desc' => $this->description($product->description),
                    'price' => $this->cents((float) $product->price),
                    'activity_price' => $product->effective_price < (float) $product->price ? $this->cents($product->effective_price) : null,
                    'head_img' => $head,
                    'status' => ($availability[$product->id] ?? false) ? 1 : 2,
                    'is_sold_separately' => true,
                    'app_modifier_group_ids' => $groupIds,
                    'priority' => (int) $product->sort_order,
                ], fn ($value) => $value !== null && $value !== '');
                $itemIds[] = $this->itemId($product->id);
                $included[] = $product->id;
            }

            if ($itemIds !== []) {
                $menuCategories[] = [
                    'app_category_id' => 'veddi-c'.$category->id,
                    'category_name' => $category->name,
                    'priority' => (int) $category->sort_order,
                    'app_item_ids' => $itemIds,
                ];
            }
        }

        if (count($groups) > self::MAX_GROUPS || count($items) > self::MAX_ITEMS) {
            throw new RuntimeException('O cardápio passa dos limites da 99Food ('.self::MAX_ITEMS.' itens e '.self::MAX_GROUPS.' grupos de complemento). Peça à 99Food a ampliação ou reduza o cardápio desta filial.');
        }

        return [
            'menu' => [
                'menus' => [[
                    'app_menu_id' => 'veddi-m'.$integration->branch_id,
                    'menu_name' => 'Cardápio',
                    'app_category_ids' => array_column($menuCategories, 'app_category_id'),
                ]],
                'categories' => $menuCategories,
                'items' => array_values($items),
                'modifier_groups' => array_values($groups),
            ],
            'issues' => $issues,
            'warnings' => $warnings,
            'products' => $included,
        ];
    }

    /**
     * Envia o cardápio completo (sobrescreve o da 99). Resultado chega no callback
     * uploadMenuTaskStatus; até lá a integração fica "running".
     *
     * @return array{task_id: string, items: int, issues: int}
     */
    public function upload(Food99Integration $integration): array
    {
        return Cache::lock("food99:catalog:{$integration->id}", 120)->block(10, function () use ($integration) {
            $built = $this->buildMenu($integration);

            if ($built['products'] === []) {
                throw new RuntimeException($built['issues'] !== []
                    ? 'Nenhum produto pôde ser enviado à 99Food. '.reset($built['issues'])
                    : 'Nenhum produto desta filial está marcado como disponível na 99Food.');
            }

            $taskId = $this->gateway->uploadMenu($integration, $built['menu']);

            $integration->update([
                'catalog_task_id' => $taskId,
                'catalog_sync_status' => 'running',
                'catalog_sync_error' => null,
            ]);

            DB::table('branch_product')->where('branch_id', $integration->branch_id)->whereIn('product_id', $built['products'])
                ->update(['food99_sync_status' => 'pending', 'food99_sync_error' => null]);
            foreach ($built['warnings'] as $productId => $warning) {
                DB::table('branch_product')->where('branch_id', $integration->branch_id)->where('product_id', $productId)
                    ->update(['food99_sync_error' => $warning]);
            }
            foreach ($built['issues'] as $productId => $issue) {
                DB::table('branch_product')->where('branch_id', $integration->branch_id)->where('product_id', $productId)
                    ->update(['food99_sync_status' => 'invalid', 'food99_sync_error' => $issue]);
            }

            Log::channel('food99')->info('99Food: cardápio enviado', [
                'food99_integration_id' => $integration->id,
                'task_id' => $taskId,
                'itens' => count($built['products']),
                'pendencias' => count($built['issues']),
            ]);

            return ['task_id' => $taskId, 'items' => count($built['products']), 'issues' => count($built['issues'])];
        });
    }

    /** Resultado da task de upload (callback ou getMenuTaskInfo). Task antiga é ignorada. */
    public function applyTaskResult(Food99Integration $integration, string $taskId, int $status, array $operationList): bool
    {
        if ($taskId === '' || $taskId !== (string) $integration->catalog_task_id) {
            return false;
        }

        $state = self::TASK_STATUSES[$status] ?? 'running';
        $failures = $this->failuresFrom($operationList);
        $synced = $this->successesFrom($operationList);

        foreach ($failures as $productId => $reason) {
            DB::table('branch_product')->where('branch_id', $integration->branch_id)->where('product_id', $productId)
                ->update(['food99_sync_status' => 'failed', 'food99_sync_error' => mb_substr($reason, 0, 255)]);
        }

        if ($state === 'running') {
            return true;
        }

        $query = DB::table('branch_product')->where('branch_id', $integration->branch_id)->where('food99_sync_status', 'pending');
        if ($state === 'failed') {
            $query->update(['food99_sync_status' => 'failed', 'food99_sync_error' => 'A 99Food recusou o envio do cardápio.']);
        } else {
            $query->when($synced !== [] && $state === 'partial', fn ($q) => $q->whereIn('product_id', $synced))
                ->update(['food99_sync_status' => 'synced', 'food99_synced_at' => now()]);
        }

        $failed = count($failures);
        $integration->update([
            'catalog_sync_status' => $state,
            'catalog_synced_at' => $state === 'failed' ? $integration->catalog_synced_at : now(),
            'catalog_sync_error' => match ($state) {
                'failed' => 'A 99Food recusou o cardápio. Confira os itens com erro e envie de novo.',
                'partial' => ($failed === 1 ? '1 item não foi aceito' : "{$failed} itens não foram aceitos").' pela 99Food. Confira o motivo na lista.',
                default => null,
            },
        ]);

        if ($state === 'failed') {
            CompanyNotification::create([
                'company_id' => $integration->company_id,
                'type' => 'food99_alert',
                'title' => '99Food: cardápio não foi aceito',
                'subtitle' => 'A 99Food recusou o envio do cardápio. Confira os itens com erro na integração.',
                'link' => route('admin.settings.99food'),
            ]);
        }

        return true;
    }

    /** Sem callback depois de um tempo: consulta a task direto. */
    public function refreshTaskStatus(Food99Integration $integration): bool
    {
        if (! $integration->catalog_task_id) {
            return false;
        }

        $info = $this->gateway->getMenuTaskInfo($integration, $integration->catalog_task_id);

        return $this->applyTaskResult($integration, (string) $integration->catalog_task_id, (int) ($info['status'] ?? -1), (array) ($info['operationList'] ?? $info['operation_list'] ?? []));
    }

    /**
     * Disponibilidade em tempo real dos produtos na filial (updateItemStatus, em lote por status).
     *
     * @param  array<int, int>  $productIds
     */
    public function syncAvailability(Food99Integration $integration, array $productIds): void
    {
        $products = Product::withoutGlobalScopes()->where('company_id', $integration->company_id)->whereIn('id', $productIds)->get();
        $availability = $this->availability($integration->branch_id, $products->modelKeys());

        $byStatus = $products->groupBy(fn (Product $product) => $product->active && $product->available_in_99food && ($availability[$product->id] ?? false) ? 1 : 2);

        foreach ($byStatus as $status => $group) {
            foreach ($group->chunk(100) as $chunk) {
                $this->gateway->updateItemStatus($integration, $chunk->map(fn (Product $product) => $this->itemId($product->id))->values()->all(), (int) $status);
            }
        }
    }

    /** Produto sai de venda na 99 (exclusão definitiva ou filial desmarcada). */
    public function disableItems(Food99Integration $integration, array $productIds): void
    {
        if ($productIds !== []) {
            $this->gateway->updateItemStatus($integration, array_map(fn (int $id) => $this->itemId($id), $productIds), 2);
        }
    }

    private function applyImageAudit(Food99Integration $integration, array $data): bool
    {
        $entries = array_is_list($data) ? $data : (array) ($data['list'] ?? [$data]);
        $touched = false;

        foreach ($entries as $entry) {
            if (! is_array($entry) || empty($entry['audit_refused_reason'])) {
                continue;
            }
            foreach ((array) ($entry['app_item_id_list'] ?? []) as $appItemId) {
                if ($productId = $this->productIdFrom((string) $appItemId)) {
                    DB::table('branch_product')->where('branch_id', $integration->branch_id)->where('product_id', $productId)
                        ->update(['food99_sync_error' => mb_substr('Foto recusada pela 99Food: '.$entry['audit_refused_reason'], 0, 255)]);
                    $touched = true;
                }
            }
        }

        return $touched;
    }

    /** @return Collection<int, Product> */
    private function sellableProducts(Food99Integration $integration): Collection
    {
        return Product::withoutGlobalScopes()
            ->where('company_id', $integration->company_id)
            ->where('active', true)
            ->where('available_in_99food', true)
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $integration->branch_id))
            ->with(['category', 'optionGroups.options'])
            ->get();
    }

    /** @return array<int, bool> disponível na filial (pivot + estoque) */
    private function availability(int $branchId, array $productIds): array
    {
        return DB::table('branch_product')->where('branch_id', $branchId)->whereIn('product_id', $productIds)
            ->get(['product_id', 'available', 'track_stock', 'quantity'])
            ->mapWithKeys(fn ($row) => [$row->product_id => (bool) $row->available && (! $row->track_stock || (int) $row->quantity > 0)])
            ->all();
    }

    /**
     * Grupo de complemento (compartilhável entre produtos, como no VEDDI). As opções viram
     * items não vendidos sozinhos, adicionados em $items.
     */
    private function groupPayload($group, array &$items): array
    {
        $mgItems = [];
        foreach ($group->options as $option) {
            $optionId = 'veddi-o'.$option->id;
            [$head] = $this->image($option->image_path ? $option->image_url : null);
            $items[$optionId] ??= array_filter([
                'app_item_id' => $optionId,
                'item_name' => Str::limit($option->name, 180, ''),
                'price' => $this->cents((float) $option->additional_price),
                'head_img' => $head,
                'status' => $option->active ? 1 : 2,
                'is_sold_separately' => false,
                'priority' => (int) $option->sort_order,
            ], fn ($value) => $value !== null && $value !== '');
            $mgItems[] = array_filter([
                'app_item_id' => $optionId,
                'price' => $this->cents((float) $option->additional_price),
                'purchase_limit' => $option->max_qty ? (int) $option->max_qty : null,
            ], fn ($value) => $value !== null);
        }

        $min = $group->allow_skip ? 0 : (int) $group->min_qty;

        return [
            'app_modifier_group_id' => $this->groupId($group->id),
            'modifier_group_name' => Str::limit($group->name, 100, ''),
            'is_required' => $min > 0 ? 1 : 2,
            'quantity_min_permitted' => $min,
            'quantity_max_permitted' => max(1, (int) $group->total_qty),
            'buy_mode' => $group->options->contains(fn ($option) => (int) $option->max_qty > 1) ? 1 : 0,
            'app_mg_items' => $mgItems,
        ];
    }

    /**
     * URL pública HTTPS (a 99 baixa dos servidores dela). JPG/PNG só; foto fora disso vai sem
     * imagem e com aviso, sem tirar o item do cardápio. [VALIDAR 99Food] se o storage do VEDDI
     * responde aos servidores da 99 sem 403.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function image(?string $url): array
    {
        if (! $url) {
            return [null, null];
        }
        if (! str_starts_with($url, 'https://')) {
            return [null, 'Foto sem endereço HTTPS público: enviada sem foto.'];
        }
        if (! preg_match('/\.(jpe?g|png)(\?.*)?$/i', $url)) {
            return [null, 'A 99Food aceita fotos JPG ou PNG: enviada sem foto.'];
        }

        return [$url, null];
    }

    private function description(?string $html): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)));

        return $text === '' ? null : Str::limit($text, 1500, '');
    }

    /** @return array<int, string> product_id => motivo */
    private function failuresFrom(array $operationList): array
    {
        $failures = [];
        foreach ($operationList as $operation) {
            $type = $operation['operationType'] ?? null;
            foreach ((array) ($operation['failedList'] ?? []) as $failed) {
                $appItemId = (string) ($failed['appItemID'] ?? $failed['app_item_id'] ?? $failed['appId'] ?? '');
                $productId = $this->productIdFrom($appItemId);
                if ($productId === null) {
                    continue;
                }
                $reason = (string) ($failed['reason'] ?? $failed['errMsg'] ?? $failed['msg'] ?? 'recusado');
                $failures[$productId] = ($type === 'imageUrlCheck' ? 'Foto recusada pela 99Food: ' : 'Recusado pela 99Food: ').$reason;
            }
        }

        return $failures;
    }

    /** @return array<int, int> */
    private function successesFrom(array $operationList): array
    {
        return collect($operationList)
            ->where('operationType', 'itemUpdate')
            ->flatMap(fn ($operation) => (array) ($operation['successList'] ?? []))
            ->map(fn ($success) => $this->productIdFrom((string) (is_array($success) ? ($success['appItemID'] ?? $success['app_item_id'] ?? '') : $success)))
            ->filter()->unique()->values()->all();
    }

    private function productIdFrom(string $appItemId): ?int
    {
        return preg_match('/^veddi-p(\d+)$/', $appItemId, $m) === 1 ? (int) $m[1] : null;
    }

    private function itemId(int $productId): string
    {
        return 'veddi-p'.$productId;
    }

    private function groupId(int $groupId): string
    {
        return 'veddi-g'.$groupId;
    }

    private function cents(float $value): int
    {
        return (int) round($value * 100);
    }
}
