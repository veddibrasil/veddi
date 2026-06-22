@props(['bodyClass' => 'min-h-screen'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="{{ $bodyClass }} bg-[#f8f8fb] dark:bg-[#0d1825]">
        <flux:sidebar sticky collapsible="mobile" class="veddi-sidebar border-e border-[#5c0079]">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ auth()->user()?->isSuperAdmin() ? route('superadmin.companies.index') : route('admin.dashboard') }}" wire:navigate />

                @auth
                    @if(!auth()->user()?->isSuperAdmin())
                        <livewire:admin.notification-bell />
                    @endif
                @endauth

                <flux:sidebar.collapse class="lg:hidden" />

            </flux:sidebar.header>

            <flux:sidebar.nav>

                @if(auth()->user()?->isSuperAdmin())
                    <flux:sidebar.group heading="Administração" class="grid">
                        <flux:sidebar.item icon="building-office-2" :href="route('superadmin.companies.index')" :current="request()->routeIs('superadmin.companies.*')" wire:navigate>
                            Empresas
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="building-storefront" :href="route('admin.branches.index')" :current="request()->routeIs('admin.branches.*')" wire:navigate>
                            Filiais
                        </flux:sidebar.item>

                    </flux:sidebar.group>
                @else
                    @php
                        $company = app()->bound('current.company') ? app('current.company') : null;
                        $user = auth()->user();
                        $can = fn(string $perm) => $company && $user?->hasPermission($perm, $company);
                    @endphp

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

                     @if( $can('pdv.operate'))
                        <flux:sidebar.group heading="PDV" class="grid">
                            @if($can('pdv.operate') && $company?->pdv_module_enabled)
                                <flux:sidebar.item icon="computer-desktop" :href="route('admin.pdv')" :current="request()->routeIs('admin.pdv')" wire:navigate>
                                    Terminal PDV
                                </flux:sidebar.item>
                                <flux:sidebar.item icon="chart-bar" :href="route('admin.pdv.report')" :current="request()->routeIs('admin.pdv.report')" wire:navigate>
                                    Relatório PDV
                                </flux:sidebar.item>
                            @endif
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

                    @if($company?->canUseFiscalNotes() && ($can('fiscal.view') || $can('fiscal.issue')))
                        <flux:sidebar.group heading="Fiscal" class="grid">
                            <flux:sidebar.item icon="document-text" :href="route('admin.fiscal.notes')" :current="request()->routeIs('admin.fiscal.notes')" wire:navigate>
                                Notas Fiscais
                            </flux:sidebar.item>
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
                @if(!auth()->user()?->isSuperAdmin())
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
                        <flux:modal.trigger name="profile-modal">
                            <flux:menu.item icon="user-circle">
                                {{ __('Meu perfil') }}
                            </flux:menu.item>
                        </flux:modal.trigger>
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

        @auth
            @php
                $overdueCompany = app()->bound('current.company') ? app('current.company') : null;
            @endphp
            @if($overdueCompany?->isOverdue())
                @php
                    $overdueDate = \Carbon\Carbon::parse($overdueCompany->overdue_since);
                    $blockDate   = $overdueDate->copy();
                    $added = 0;
                    while ($added < 3) {
                        $blockDate->addDay();
                        if (! $blockDate->isWeekend()) $added++;
                    }
                    $daysLeft = (int) now()->startOfDay()->diffInDays($blockDate->startOfDay(), false);
                @endphp
                <div class="bg-yellow-50 border-b border-yellow-300 px-4 py-3 text-sm text-yellow-800 flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0 text-yellow-500" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                    </svg>
                    <span>
                        <strong>Fatura em atraso.</strong>
                        @if($daysLeft > 0)
                            O sistema será bloqueado em <strong>{{ $daysLeft }} {{ $daysLeft === 1 ? 'dia útil' : 'dias úteis' }}</strong>.
                        @else
                            O sistema será bloqueado em breve.
                        @endif
                        <a href="{{ route('admin.billing') }}" class="underline font-medium" wire:navigate>Regularizar agora</a>
                    </span>
                </div>
            @endif
        @endauth

        {{ $slot }}

        @auth
            <livewire:admin.notifications />
            <livewire:admin.profile-modal />
        @endauth

        @fluxScripts
    </body>
</html>
