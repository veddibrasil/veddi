<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[#FCFCFC] dark:bg-[#18100e]">
        <flux:sidebar sticky collapsible="mobile" class="delivry-sidebar border-e border-[#e6b428]">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('admin.dashboard') }}" wire:navigate />

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
                    <flux:sidebar.group :heading="__('Platform')" class="grid">
                        <flux:sidebar.item icon="home" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>
                            {{ __('Dashboard') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="Pedidos" class="grid">
                        <flux:sidebar.item icon="list-bullet" :href="route('admin.orders.index')" :current="request()->routeIs('admin.orders.*')" wire:navigate>
                            Pedidos
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="Cardápio" class="grid">
                        <flux:sidebar.item icon="building-storefront" :href="route('admin.branches.index')" :current="request()->routeIs('admin.branches.*')" wire:navigate>
                            Filiais
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="tag" :href="route('admin.categories.index')" :current="request()->routeIs('admin.categories.*')" wire:navigate>
                            Categorias
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="shopping-bag" :href="route('admin.products.index')" :current="request()->routeIs('admin.products.*')" wire:navigate>
                            Produtos
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="archive-box" :href="route('admin.stock.index')" :current="request()->routeIs('admin.stock.*')" wire:navigate>
                            Estoque
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    @if(auth()->user()?->isCompanyAdmin(app()->bound('current.company') ? app('current.company') : new \App\Models\Company))
                        <flux:sidebar.group heading="Equipe" class="grid">
                            <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>
                                Usuários
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="user-group" :href="route('admin.roles.index')" :current="request()->routeIs('admin.roles.*')" wire:navigate>
                                Tipos de Usuário
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                        <flux:sidebar.group heading="Configurações" class="grid">
                            <flux:sidebar.item icon="cog-6-tooth" :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>
                                Empresa
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
        <flux:header class="lg:hidden bg-[#ffc72d] border-b border-[#e6b428]">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

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
                            {{ __('Settings') }}
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
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @auth
            <livewire:admin.notifications />
        @endauth

        @fluxScripts
    </body>
</html>
