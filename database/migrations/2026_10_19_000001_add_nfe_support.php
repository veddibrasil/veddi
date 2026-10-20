<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->json('fiscal_recipient')->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->text('fiscal_error')->nullable();
            $table->json('fiscal_recipient')->nullable();
        });
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->boolean('nfe_enabled')->default(false);
            $table->unsignedSmallInteger('nfe_serie')->default(1);
        });
        Schema::table('fiscal_notes', function (Blueprint $table) {
            $table->string('model', 2)->default('65')->index();
            $table->foreignId('fiscal_config_id')->nullable()->constrained('company_fiscal_configs')->nullOnDelete();
            $table->string('environment')->nullable();
            $table->string('issuer_cnpj', 14)->nullable();
            $table->string('number')->nullable();
            $table->unsignedSmallInteger('series')->nullable();
            $table->string('protocol')->nullable();
        });
        Schema::create('fiscal_note_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('fiscal_note_id')->nullable()->constrained();
            $table->foreignId('fiscal_config_id')->nullable()->constrained('company_fiscal_configs');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_note_events');
        Schema::table('fiscal_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiscal_config_id');
            $table->dropColumn(['model', 'environment', 'issuer_cnpj', 'number', 'series', 'protocol']);
        });
        Schema::table('company_fiscal_configs', fn (Blueprint $table) => $table->dropColumn(['nfe_enabled', 'nfe_serie']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['fiscal_recipient', 'fiscal_error']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('fiscal_recipient'));
    }
};
