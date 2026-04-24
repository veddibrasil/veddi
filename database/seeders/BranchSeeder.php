<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            [
                'name' => 'Mister Coxinha — Centro',
                'address' => 'Av. Brasil, 100',
                'city' => 'Maringá',
                'phone' => '(44) 99999-0001',
                'active' => true,
                'opens_at' => '10:00',
                'closes_at' => '20:00',
            ],
            [
                'name' => 'Mister Coxinha — Zona Sul',
                'address' => 'Rua das Flores, 250',
                'city' => 'Maringá',
                'phone' => '(44) 99999-0002',
                'active' => true,
                'opens_at' => '10:00',
                'closes_at' => '20:00',
            ],
        ];

        foreach ($branches as $data) {
            Branch::firstOrCreate(['name' => $data['name']], $data);
        }
    }
}
