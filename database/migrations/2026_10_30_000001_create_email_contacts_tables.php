<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contatos externos do e-mail marketing (possíveis clientes que ainda não têm conta): listas
 * importadas pelo super admin, com a origem e o aceite registrados na criação da lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_contact_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // De onde vieram os contatos (formulário do site, evento...): registro do consentimento.
            $table->string('source', 300);
            $table->timestamp('consent_confirmed_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('email_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('email_contact_lists')->cascadeOnDelete();
            // Sempre minúsculo e sem espaços (SuppressionService::normalize).
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('company_name')->nullable();
            $table->timestamps();

            $table->unique(['list_id', 'email']);
            $table->index('email');
        });

        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            // Apagar o contato (ou a lista) apaga o destinatário: some o dado pessoal e o envio
            // que ainda não saiu não sai mais.
            $table->foreignId('contact_id')->nullable()->after('company_id')->constrained('email_contacts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });

        Schema::dropIfExists('email_contacts');
        Schema::dropIfExists('email_contact_lists');
    }
};
