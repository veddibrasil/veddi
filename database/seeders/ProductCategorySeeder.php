<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Coxinhas',   'sort_order' => 1],
            ['name' => 'Esfihas',    'sort_order' => 2],
            ['name' => 'Kibes',      'sort_order' => 3],
            ['name' => 'Bolinhos',   'sort_order' => 4],
            ['name' => 'Combos',     'sort_order' => 5],
        ];

        foreach ($categories as $data) {
            ProductCategory::firstOrCreate(['name' => $data['name']], $data);
        }
    }
}
