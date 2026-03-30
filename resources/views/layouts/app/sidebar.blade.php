<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[#f8f8fb] dark:bg-[#0d1825]">
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

                    @if($can('orders.view'))
                        <flux:sidebar.group heading="Pedidos" class="grid">
                            <flux:sidebar.item icon="list-bullet" :href="route('admin.orders.index')" :current="request()->routeIs('admin.orders.*')" wire:navigate>
                                Pedidos
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="chat-bubble-left-right" :href="route('admin.support.index')" :current="request()->routeIs('admin.support.*')" wire:navigate>
                                Suporte
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
                            <flux:sidebar.item icon="banknotes" :href="route('admin.wallet')" :current="request()->routeIs('admin.wallet')" wire:navigate>
                                Carteira
                            </flux:sidebar.item>
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
            <livewire:admin.notifications />
        @endauth

        {{-- WhatsApp support button --}}
        @php $waPhone = config('support.whatsapp_number'); @endphp
        @if($waPhone)
            <a
                href="https://wa.me/{{ preg_replace('/\D/', '', $waPhone) }}?text={{ urlencode('Olá, preciso de suporte com o sistema.') }}"
                target="_blank"
                rel="noopener"
                title="Falar com suporte via WhatsApp"
                class="fixed bottom-6 right-6 z-50 flex items-center justify-center w-13 h-13 rounded-full shadow-lg transition-transform hover:scale-110 active:scale-95"
                style="background-color: #25D366;width: 52px;height: 52px;position: fixed;right: 1%;bottom: 3%;"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-white" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                    <path d="M12 0C5.373 0 0 5.373 0 12c0 2.125.555 4.122 1.528 5.855L.057 23.522a.75.75 0 00.921.921l5.668-1.471A11.943 11.943 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.75a9.698 9.698 0 01-4.944-1.352l-.355-.21-3.667.952.972-3.565-.23-.368A9.698 9.698 0 012.25 12C2.25 6.615 6.615 2.25 12 2.25S21.75 6.615 21.75 12 17.385 21.75 12 21.75z"/>
                </svg>
            </a>
        @endif

        @fluxScripts
    </body>
</html>
