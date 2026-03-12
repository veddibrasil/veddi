<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrCreate(
            ['slug' => 'mister-coxinha'],
            [
                'name'                    => 'Mister Coxinha',
                'slug'                    => 'mister-coxinha',
                'subdomain'               => null,
                'primary_color'           => '#B91C1C',
                'primary_color_dark'      => '#7F1D1D',
                'primary_color_light'     => '#DC2626',
                'secondary_color'         => '#B45309',
                'secondary_color_light'   => '#D97706',
                'accent_color'            => '#FEF3C7',
                'tagline'                 => 'O melhor salgado da cidade!',
                'footer_text'             => '© ' . date('Y') . ' Mister Coxinha. Todos os direitos reservados.',
                'order_prefix'            => 'MXC',
                'active'                  => true,
            ]
        );

        // Super admin (acesso a todos os tenants)
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@mistercoxinha.com.br'],
            [
                'name'           => 'Admin',
                'email'          => 'admin@mistercoxinha.com.br',
                'password'       => Hash::make('password'),
                'is_super_admin' => true,
            ]
        );

        // Company admin vinculado à empresa padrão
        $companyAdmin = User::firstOrCreate(
            ['email' => 'gerente@mistercoxinha.com.br'],
            [
                'name'     => 'Gerente',
                'email'    => 'gerente@mistercoxinha.com.br',
                'password' => Hash::make('password'),
            ]
        );

        $company->users()->syncWithoutDetaching([
            $companyAdmin->id => ['role' => 'company_admin'],
        ]);

        // Bind company for seeders that use BelongsToCompany trait
        app()->instance('current.company', $company);

        $this->call([
            PermissionSeeder::class,
            BranchSeeder::class,
            ProductCategorySeeder::class,
            ProductSeeder::class,
        ]);
    }
}
