<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $coxinhas = ProductCategory::where('name', 'Coxinhas')->first();
        $esfihas = ProductCategory::where('name', 'Esfihas')->first();
        $kibes = ProductCategory::where('name', 'Kibes')->first();
        $bolinhos = ProductCategory::where('name', 'Bolinhos')->first();
        $combos = ProductCategory::where('name', 'Combos')->first();

        $products = [
            // Coxinhas
            ['category' => $coxinhas, 'name' => 'Coxinha de Frango',          'price' => 5.00,  'sort_order' => 1],
            ['category' => $coxinhas, 'name' => 'Coxinha de Frango c/ Requeijão', 'price' => 6.00, 'sort_order' => 2],
            ['category' => $coxinhas, 'name' => 'Coxinha de Carne',            'price' => 5.50,  'sort_order' => 3],

            // Esfihas
            ['category' => $esfihas,  'name' => 'Esfiha de Carne',             'price' => 4.50,  'sort_order' => 1],
            ['category' => $esfihas,  'name' => 'Esfiha de Queijo',             'price' => 4.50,  'sort_order' => 2],
            ['category' => $esfihas,  'name' => 'Esfiha de Frango',             'price' => 4.50,  'sort_order' => 3],

            // Kibes
            ['category' => $kibes,    'name' => 'Kibe Frito',                  'price' => 4.00,  'sort_order' => 1],
            ['category' => $kibes,    'name' => 'Kibe Assado',                 'price' => 4.00,  'sort_order' => 2],

            // Bolinhos
            ['category' => $bolinhos, 'name' => 'Bolinho de Bacalhau',         'price' => 5.50,  'sort_order' => 1],
            ['category' => $bolinhos, 'name' => 'Bolinho de Carne',            'price' => 4.50,  'sort_order' => 2],

            // Combos
            ['category' => $combos,   'name' => 'Combo 10 Coxinhas',           'price' => 45.00, 'sort_order' => 1],
            ['category' => $combos,   'name' => 'Combo Festa 30 Salgados',     'price' => 120.00, 'sort_order' => 2],
        ];

        $branches = Branch::all();

        foreach ($products as $data) {
            if (! $data['category']) {
                continue;
            }

            $product = Product::firstOrCreate(
                ['name' => $data['name'], 'product_category_id' => $data['category']->id],
                [
                    'price' => $data['price'],
                    'sort_order' => $data['sort_order'],
                    'active' => true,
                ]
            );

            // Make available in all branches
            $sync = [];
            foreach ($branches as $branch) {
                $sync[$branch->id] = ['available' => true];
            }
            $product->branches()->sync($sync);
        }
    }
}
