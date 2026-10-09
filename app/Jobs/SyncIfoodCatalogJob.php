<?php

namespace App\Jobs;

use App\Exceptions\IfoodApiException;
use App\Exceptions\IfoodCatalogItemException;
use App\Models\IfoodIntegration;
use App\Models\Product;
use App\Services\Ifood\IfoodCatalogSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sem $productId: sync completo da filial (batch periódico de segurança ou envio pedido na tela).
 * Com $productId: sync de disponibilidade em tempo real de 1 produto (disparado
 * por ProductObserver quando active/available_in_ifood mudam e pelo estoque).
 * Com $syncDetails: sincroniza também nome, foto e complementos após salvar o formulário.
 * Com $publish: o restaurante pediu pra publicar o cardápio — libera os envios automáticos.
 *
 * Publicação é opt-in: enquanto o restaurante não publica, nenhum sync automático envia itens
 * (evita duplicar o cardápio de quem já vendia no iFood antes de conectar).
 */
class SyncIfoodCatalogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Outro envio da mesma loja pode estar segurando o lock por alguns minutos. */
    public array $backoff = [30, 120, 300, 600];

    public function __construct(public int $branchId, public ?int $productId = null, public bool $syncDetails = false, public bool $publish = false) {}

    public function handle(IfoodCatalogSyncService $catalogSyncService): void
    {
        $integration = IfoodIntegration::withoutGlobalScopes()
            ->where('branch_id', $this->branchId)
            ->where('status', 'active')
            ->first();

        if (! $integration || ! $integration->isOperational()) {
            // Suspensa (ou empresa bloqueada) entre o clique e a fila: sem isso o envio ficava
            // "na fila" pra sempre, com o botão travado e a publicação nunca registrada.
            $this->abandonQueuedSync('O envio foi cancelado porque a integração não está ativa. Retome a integração e sincronize de novo.');

            return;
        }

        if (! $this->publish && ! $integration->hasPublishedCatalog()) {
            return;
        }

        $previousCompany = app()->bound('current.company') ? app('current.company') : null;
        try {
            app()->instance('current.company', $integration->company);

            if ($this->productId) {
                $product = Product::withoutGlobalScopes()->where('company_id', $integration->company_id)->find($this->productId);
                if ($product) {
                    if ($this->syncDetails) {
                        $catalogSyncService->syncProduct($integration, $product);
                    } else {
                        $catalogSyncService->syncAvailability($integration->branch, $product);
                    }
                }

                return;
            }

            $this->syncFull($catalogSyncService, $integration);
        } finally {
            if ($previousCompany) {
                app()->instance('current.company', $previousCompany);
            } else {
                app()->forgetInstance('current.company');
            }
        }
    }

    private function syncFull(IfoodCatalogSyncService $catalogSyncService, IfoodIntegration $integration): void
    {
        $changes = ['catalog_sync_status' => 'running', 'catalog_sync_error' => null];
        if ($this->publish && ! $integration->hasPublishedCatalog()) {
            $changes['catalog_published_at'] = now();
        }
        $integration->update($changes);

        // Falha relança pra fila tentar de novo; esgotadas as tentativas, failed() mostra o erro na tela.
        try {
            $result = $catalogSyncService->syncFullCatalog($integration);
        } catch (IfoodCatalogItemException $e) {
            // Nenhum item aceito: o motivo de cada um já ficou no cadastro, e repetir na fila só
            // reenviaria tudo por ~17 min com o botão travado. O restaurante corrige e envia de novo.
            $this->failed($e);

            return;
        }

        $failed = count($result['failed']);
        $integration->update([
            'catalog_sync_status' => $failed > 0 ? 'partial' : 'done',
            'catalog_sync_error' => $failed > 0
                ? ($failed === 1 ? '1 produto não foi enviado.' : "{$failed} produtos não foram enviados.").' Confira o motivo na lista de produtos.'
                : null,
        ]);
    }

    private function abandonQueuedSync(string $reason): void
    {
        if ($this->productId !== null) {
            return;
        }

        IfoodIntegration::withoutGlobalScopes()->where('branch_id', $this->branchId)
            ->whereIn('catalog_sync_status', ['queued', 'running'])
            ->update(['catalog_sync_status' => 'failed', 'catalog_sync_error' => $reason]);
    }

    public function failed(?Throwable $e): void
    {
        if ($this->productId !== null) {
            return;
        }

        IfoodIntegration::withoutGlobalScopes()->where('branch_id', $this->branchId)->update([
            'catalog_sync_status' => 'failed',
            'catalog_sync_error' => match (true) {
                $e instanceof IfoodApiException => 'A sincronização não foi concluída. '.$e->userMessage(),
                $e instanceof IfoodCatalogItemException => $e->getMessage(),
                default => 'A sincronização não foi concluída. Confira fotos, categorias e a conexão com o iFood antes de tentar de novo.',
            },
        ]);
    }
}
