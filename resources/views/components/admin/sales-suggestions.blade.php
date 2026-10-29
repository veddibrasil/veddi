@props(['context' => 'all', 'productId' => null, 'orderId' => null, 'branchId' => null, 'categoryId' => null])

@if (app()->bound('current.company') && auth()->user()?->isCompanyAdmin(app('current.company')))
    <livewire:admin.sales-suggestions
        :context="$context"
        :product-id="$productId"
        :order-id="$orderId"
        :branch-id="$branchId ? (int) $branchId : null"
        :category-id="$categoryId ? (int) $categoryId : null"
        :key="'sales-suggestions-'.implode('-', [$context, $productId, $orderId, $branchId, $categoryId])"
    />
@endif
