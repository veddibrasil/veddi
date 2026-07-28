@props(['bodyClass' => 'min-h-screen'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    @php
        $company = app()->bound('current.company') ? app('current.company') : null;
        $user = auth()->user();
        $can = fn(string $perm) => $company && $user?->hasPermission($perm, $company);
        // Garçom só opera mesas/comandas — sem acesso ao dashboard nem ao resto do painel.
        $isWaiterOnly = $can('pdv.waiter_operate') && !$can('pdv.operate');
        // Entregador só opera a fila de entregas — sem acesso ao dashboard nem ao resto do painel.
        $isDeliveryOnly = $company && $user?->roleForCompany($company) === 'entrega';
        // Cozinha/bar só operam a própria fila de pedidos — sem acesso ao dashboard nem ao resto do painel.
        $isStationOnly = $company && in_array($user?->roleForCompany($company), ['cozinha', 'bar']);
    @endphp
    <body class="{{ $bodyClass }} bg-[#f8f8fb] dark:bg-[#0d1825]">
        <flux:sidebar sticky collapsible="mobile" class="veddi-sidebar border-e border-[#5c0079]">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ auth()->user()?->isSuperAdmin() ? route('superadmin.dashboard') : (($isWaiterOnly ? route('admin.pdv.tabs') : (($isDeliveryOnly || $isStationOnly) ? route('admin.orders.index') : route('admin.dashboard')))) }}" wire:navigate />

                @auth
                    @if(!auth()->user()?->isSuperAdmin() && !$isWaiterOnly)
                        <livewire:admin.notification-bell />
                    @endif
                @endauth

                <flux:sidebar.collapse class="lg:hidden" />

            </flux:sidebar.header>

            <flux:sidebar.nav>

                @if(auth()->user()?->isSuperAdmin())
                    <flux:sidebar.group heading="Administração" class="grid">
                        <flux:sidebar.item icon="home" :href="route('superadmin.dashboard')" :current="request()->routeIs('superadmin.dashboard')" wire:navigate>
                            Dashboard
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="building-office-2" :href="route('superadmin.companies.index')" :current="request()->routeIs('superadmin.companies.*')" wire:navigate>
                            Empresas
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @else
                    @if($isWaiterOnly)
                        <flux:sidebar.group heading="PDV" class="grid">
                            <flux:sidebar.item icon="table-cells" :href="route('admin.pdv.tabs')" :current="request()->routeIs('admin.pdv.tabs')" wire:navigate>
                                Mesas / Comandas
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                    @elseif($isDeliveryOnly)
                        <flux:sidebar.group heading="Entregas" class="grid">
                            <flux:sidebar.item icon="truck" :href="route('admin.orders.index')" :current="request()->routeIs('admin.orders.*')" wire:navigate>
                                Minhas entregas
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                    @elseif($isStationOnly)
                        <flux:sidebar.group heading="Minha fila" class="grid">
                            <flux:sidebar.item icon="fire" :href="route('admin.orders.index')" :current="request()->routeIs('admin.orders.*')" wire:navigate>
                                Pedidos
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                    @else

                    <flux:sidebar.group :heading="__('Plataforma')" class="grid">
                        <flux:sidebar.item icon="home" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>
                            {{ __('Dashboard') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    @if($can('orders.view') || $can('pdv.operate'))
                        <flux:sidebar.group heading="Pedidos" class="grid">
                            @if($can('orders.view'))
                                <flux:sidebar.item icon="list-bullet" :href="route('admin.orders.index')" :current="request()->routeIs('admin.orders.index') || request()->routeIs('admin.orders.show')" wire:navigate>
                                    Pedidos
                                </flux:sidebar.item>
                            @endif
    
                        </flux:sidebar.group>
                    @endif

                    @if ($can('orders.report'))
                        <flux:sidebar.item icon="chart-bar" :href="route('admin.orders.report')" :current="request()->routeIs('admin.orders.report')" wire:navigate>
                            Relatório
                        </flux:sidebar.item>
                    @endif

                    @if($can('pdv.operate') && $company?->pdv_module_enabled)
                        <flux:sidebar.group heading="PDV" class="grid">
                                <flux:sidebar.item icon="computer-desktop" :href="route('admin.pdv')" :current="request()->routeIs('admin.pdv') || request()->routeIs('admin.pdv.checkout') || request()->routeIs('admin.pdv.tabs')" wire:navigate>
                                    Terminal PDV
                                </flux:sidebar.item>
                                <flux:sidebar.item icon="chart-bar" :href="route('admin.pdv.report')" :current="request()->routeIs('admin.pdv.report')" wire:navigate>
                                    Relatório PDV
                                </flux:sidebar.item>
                        </flux:sidebar.group>
                    @endif

                    @php
                        $isBranchManager = $company && $user?->isBranchManager($company);
                        $showBranches   = $can('branches.view') && !$isBranchManager;
                        $showCategories = $can('categories.view');
                        $showProducts   = $can('products.view');
                        $showStock      = $can('stock.view');
                        $showCoupons    = $can('coupons.view');
                        $showCardapio   = $showBranches || $showCategories || $showProducts || $showStock || $showCoupons;
                    @endphp

                    @if($showCardapio)
                        <flux:sidebar.group heading="Cardápio" class="grid">
                            @if($showBranches)
                                <flux:sidebar.item icon="building-storefront" :href="route('admin.branches.index')" :current="request()->routeIs('admin.branches.*')" wire:navigate>
                                    Filiais
                                </flux:sidebar.item>
                            @endif
                            @if($showCategories)
                                <flux:sidebar.item icon="tag" :href="route('admin.categories.index')" :current="request()->routeIs('admin.categories.*')" wire:navigate>
                                    Categorias
                                </flux:sidebar.item>
                            @endif
                            @if($showProducts)
                                <flux:sidebar.item icon="shopping-bag" :href="route('admin.products.index')" :current="request()->routeIs('admin.products.*')" wire:navigate>
                                    Produtos
                                </flux:sidebar.item>
                            @endif
                            @if($showStock)
                                <flux:sidebar.item icon="archive-box" :href="route('admin.stock.index')" :current="request()->routeIs('admin.stock.*')" wire:navigate>
                                    Estoque
                                </flux:sidebar.item>
                            @endif
                            @if($showCoupons)
                                <flux:sidebar.item icon="ticket" :href="route('admin.coupons.index')" :current="request()->routeIs('admin.coupons.*')" wire:navigate>
                                    Cupons
                                </flux:sidebar.item>
                            @endif
                        </flux:sidebar.group>
                    @endif

                    @if($company?->canUseFiscalNotes() && ($can('fiscal.view') || $can('fiscal.issue') || $can('fiscal.settings')))
                        <flux:sidebar.group heading="Fiscal" class="grid">
                            @if($can('fiscal.view') || $can('fiscal.issue'))
                                <flux:sidebar.item icon="document-text" :href="route('admin.fiscal.notes')" :current="request()->routeIs('admin.fiscal.notes')" wire:navigate>
                                    Notas Fiscais
                                </flux:sidebar.item>
                            @endif
                            @if($can('fiscal.settings'))
                                <flux:sidebar.item icon="cog-6-tooth" :href="route('admin.fiscal.config')" :current="request()->routeIs('admin.fiscal.config')" wire:navigate>
                                    Configurações
                                </flux:sidebar.item>
                            @endif
                        </flux:sidebar.group>
                    @endif

                    @php
                        $showUsers = $can('users.view') || $can('users.manage');
                        $showRoles = $can('roles.manage');
                        $showSettings = $can('company.settings');
                    @endphp

                    @if($showUsers || $showRoles)
                        <flux:sidebar.group heading="Equipe" class="grid">
                            @if($showUsers)
                                <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>
                                    Usuários
                                </flux:sidebar.item>
                            @endif
                            @if($showRoles)
                                <flux:sidebar.item icon="user-group" :href="route('admin.roles.index')" :current="request()->routeIs('admin.roles.*')" wire:navigate>
                                    Tipos de Usuário
                                </flux:sidebar.item>
                            @endif
                        </flux:sidebar.group>
                    @endif

                    @if($showSettings)
                        <flux:sidebar.group heading="Configurações" class="grid">
                            <flux:sidebar.item icon="cog-6-tooth" :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>
                                Empresa
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="credit-card" :href="route('admin.billing')" :current="request()->routeIs('admin.billing')" wire:navigate>
                                Assinatura
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="banknotes" :href="route('admin.wallet')" :current="request()->routeIs('admin.wallet')" wire:navigate target="_blank">
                                Carteira (Yapay)
                            </flux:sidebar.item>
                            {{-- <flux:sidebar.item icon="chat-bubble-left-ellipsis" :href="route('admin.settings.whatsapp')" :current="request()->routeIs('admin.settings.whatsapp')" wire:navigate>
                                WhatsApp
                            </flux:sidebar.item> --}}
                        </flux:sidebar.group>
                    @endif

                    @endif
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            {{-- Theme toggle --}}
            <div x-data class="px-3 pb-2">
                <flux:radio.group variant="segmented" x-model="$flux.appearance" class="w-full">
                    <flux:radio value="light" icon="sun" />
                    <flux:radio value="dark" icon="moon" />
                    <flux:radio value="system" icon="computer-desktop" />
                </flux:radio.group>
            </div>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden bg-[#7A00A3] border-b border-[#5c0079]">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            @auth
                @if(!auth()->user()?->isSuperAdmin() && !$isWaiterOnly)
                    <livewire:admin.notification-bell />
                @endif
            @endauth

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
            
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Configurações') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Sair') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @auth
            @if(!$isWaiterOnly)
                <livewire:admin.notifications />
            @endif
            <livewire:admin.profile-modal />
        @endauth

        @fluxScripts
    </body>
</html>
