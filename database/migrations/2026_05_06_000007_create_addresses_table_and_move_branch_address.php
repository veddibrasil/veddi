<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->string('line1', 255)->nullable();
            $table->string('number', 20)->nullable();
            $table->string('complement', 100)->nullable();
            $table->string('neighborhood', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('cep', 9)->nullable();
            $table->timestamps();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('address_id')->nullable()->after('name')->constrained('addresses')->nullOnDelete();
        });

        // Backfill existing branch addresses into the new table
        $branches = DB::table('branches')->select('id', 'address', 'city')->get();
        foreach ($branches as $branch) {
            $addressId = DB::table('addresses')->insertGetId([
                'line1' => $branch->address,
                'city' => $branch->city,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('branches')->where('id', $branch->id)->update(['address_id' => $addressId]);
        }

        // Drop legacy columns
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['address', 'city']);
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('address')->default('')->after('name');
            $table->string('city')->default('')->after('address');
        });

        // Backfill legacy columns from addresses table (best-effort)
        $branches = DB::table('branches')->select('id', 'address_id')->get();
        foreach ($branches as $branch) {
            if (! $branch->address_id) {
                continue;
            }

            $addr = DB::table('addresses')->where('id', $branch->address_id)->first();
            if (! $addr) {
                continue;
            }

            DB::table('branches')->where('id', $branch->id)->update([
                'address' => $addr->line1 ?? '',
                'city' => $addr->city ?? '',
            ]);
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('address_id');
        });

        Schema::dropIfExists('addresses');
    }
};
