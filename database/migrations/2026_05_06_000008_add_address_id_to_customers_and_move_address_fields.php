<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('address_id')->nullable()->after('tax_id')->constrained('addresses')->nullOnDelete();
        });

        $customers = DB::table('customers')
            ->select('id', 'address', 'number', 'complement', 'neighborhood', 'city', 'cep')
            ->get();

        foreach ($customers as $customer) {
            $hasAny =
                ($customer->address ?? '') !== ''
                || ($customer->number ?? '') !== ''
                || ($customer->complement ?? '') !== ''
                || ($customer->neighborhood ?? '') !== ''
                || ($customer->city ?? '') !== ''
                || ($customer->cep ?? '') !== '';

            if (! $hasAny) {
                continue;
            }

            $addressId = DB::table('addresses')->insertGetId([
                'line1' => $customer->address,
                'number' => $customer->number,
                'complement' => $customer->complement,
                'neighborhood' => $customer->neighborhood,
                'city' => $customer->city,
                'cep' => $customer->cep,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('customers')->where('id', $customer->id)->update(['address_id' => $addressId]);
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['address', 'number', 'complement', 'neighborhood', 'city', 'cep']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('number', 20)->nullable()->after('phone');
            $table->string('address')->nullable()->after('tax_id');
            $table->string('neighborhood')->nullable()->after('address');
            $table->string('city')->nullable()->after('neighborhood');
            $table->string('cep', 9)->nullable()->after('city');
            $table->string('complement', 100)->nullable()->after('cep');
        });

        $customers = DB::table('customers')->select('id', 'address_id')->get();
        foreach ($customers as $customer) {
            if (! $customer->address_id) {
                continue;
            }

            $addr = DB::table('addresses')->where('id', $customer->address_id)->first();
            if (! $addr) {
                continue;
            }

            DB::table('customers')->where('id', $customer->id)->update([
                'address' => $addr->line1,
                'number' => $addr->number,
                'complement' => $addr->complement,
                'neighborhood' => $addr->neighborhood,
                'city' => $addr->city,
                'cep' => $addr->cep,
            ]);
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('address_id');
        });
    }
};
