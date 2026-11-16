<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tributação em lote: modelos fiscais da empresa (valores aprovados pela contabilidade) e o
 * histórico de cada aplicação. Aplicar copia os valores para products.fiscal_data ou
 * branch_product.fiscal_data; não há herança dinâmica — editar o modelo depois não muda produto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedTinyInteger('crt')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('operation', 80)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->json('data');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('fiscal_template_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_name', 80);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target', 10);
            $table->string('mode', 10);
            $table->unsignedInteger('products_count');
            $table->json('changes');
            $table->timestamp('reverted_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_template_applications');
        Schema::dropIfExists('fiscal_templates');
    }
};
