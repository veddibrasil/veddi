<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_address_id')->nullable()->after('order_type')->constrained('addresses')->nullOnDelete();
        });

        $orders = DB::table('orders')
            ->select(
                'id',
                'order_type',
                'delivery_address',
                'delivery_number',
                'delivery_neighborhood',
                'delivery_city',
                'delivery_cep',
                'delivery_complement'
            )
            ->get();

        foreach ($orders as $order) {
            if ($order->order_type !== 'delivery') {
                continue;
            }

            $hasAny =
                ($order->delivery_address ?? '') !== ''
                || ($order->delivery_number ?? '') !== ''
                || ($order->delivery_neighborhood ?? '') !== ''
                || ($order->delivery_city ?? '') !== ''
                || ($order->delivery_cep ?? '') !== ''
                || ($order->delivery_complement ?? '') !== '';

            if (! $hasAny) {
                continue;
            }

            $addressId = DB::table('addresses')->insertGetId([
                'line1' => $order->delivery_address,
                'number' => $order->delivery_number,
                'complement' => $order->delivery_complement,
                'neighborhood' => $order->delivery_neighborhood,
                'city' => $order->delivery_city,
                'cep' => $order->delivery_cep,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('orders')->where('id', $order->id)->update(['delivery_address_id' => $addressId]);
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_address',
                'delivery_number',
                'delivery_neighborhood',
                'delivery_city',
                'delivery_cep',
                'delivery_complement',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_address')->nullable()->after('order_type');
            $table->string('delivery_number', 20)->nullable()->after('delivery_address');
            $table->string('delivery_neighborhood')->nullable()->after('delivery_number');
            $table->string('delivery_city')->nullable()->after('delivery_neighborhood');
            $table->string('delivery_cep', 9)->nullable()->after('delivery_city');
            $table->string('delivery_complement', 100)->nullable()->after('delivery_cep');
        });

        $orders = DB::table('orders')->select('id', 'delivery_address_id')->get();
        foreach ($orders as $order) {
            if (! $order->delivery_address_id) {
                continue;
            }

            $addr = DB::table('addresses')->where('id', $order->delivery_address_id)->first();
            if (! $addr) {
                continue;
            }

            DB::table('orders')->where('id', $order->id)->update([
                'delivery_address' => $addr->line1,
                'delivery_number' => $addr->number,
                'delivery_complement' => $addr->complement,
                'delivery_neighborhood' => $addr->neighborhood,
                'delivery_city' => $addr->city,
                'delivery_cep' => $addr->cep,
            ]);
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_address_id');
        });
    }
};
