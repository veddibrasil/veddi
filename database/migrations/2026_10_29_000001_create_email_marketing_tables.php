<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail marketing do super admin. Módulo da plataforma: nenhuma tabela tem company_id
 * obrigatório; os destinatários guardam a empresa só para filtro e auditoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('marketing_opt_in_at')->nullable();
            $table->timestamp('marketing_opt_out_at')->nullable();
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject_default')->nullable();
            $table->string('preheader_default')->nullable();
            $table->json('blocks');
            $table->longText('html');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('email_segments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('filters');
            $table->unsignedInteger('last_count')->nullable();
            $table->timestamp('last_counted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // campaign = disparo único; automation = passo de uma automação (envio contínuo).
            $table->string('kind', 20)->default('campaign');
            $table->string('subject')->default('');
            $table->string('preheader')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            $table->foreignId('template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->foreignId('segment_id')->nullable()->constrained('email_segments')->nullOnDelete();
            $table->json('filters')->nullable();
            $table->json('blocks')->nullable();
            $table->longText('html')->nullable();
            // Congelado no disparo: layout + conteúdo, com as variáveis ainda por substituir.
            $table->longText('html_snapshot')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('test_sent_at')->nullable();
            $table->string('last_error', 500)->nullable();

            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->unsignedInteger('complained_count')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            // Teste A/B de assunto: amostra dividida entre A (subject) e B; a vencedora vai ao restante.
            $table->string('ab_subject_b')->nullable();
            $table->unsignedTinyInteger('ab_sample_percent')->nullable();
            $table->unsignedSmallInteger('ab_wait_hours')->nullable();
            $table->char('ab_winner', 1)->nullable();
            $table->timestamp('ab_decided_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('email_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->index();
            $table->string('name')->nullable();
            $table->json('variables')->nullable();
            $table->char('variant', 1)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable()->index();
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('first_clicked_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            // Um envio por pessoa por campanha, mesmo com retry de job ou disparo repetido.
            $table->unique(['campaign_id', 'email']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('email_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained('email_campaign_recipients')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('url', 2048)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip_hash', 64)->nullable();
            // Idempotência do webhook: a mesma entrega do provedor conta uma vez só.
            $table->string('provider_event_id')->nullable()->unique();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['recipient_id', 'type']);
            $table->index(['campaign_id', 'type']);
        });

        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            // Sempre minúsculo e sem espaços (SuppressionService::normalize).
            $table->string('email')->unique();
            $table->string('reason', 20);
            $table->foreignId('source_campaign_id')->nullable()->constrained('email_campaigns')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('email_automations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trigger', 40);
            $table->json('trigger_settings')->nullable();
            $table->boolean('active')->default(false);
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('email_automation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained('email_automations')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            // Espera desde a entrada (passo 1) ou desde o passo anterior.
            $table->unsignedSmallInteger('delay_hours')->default(0);
            // Cada passo envia por uma campanha kind=automation: reaproveita destinatários,
            // supressão, descadastro, rastreio e métricas.
            $table->foreignId('campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['automation_id', 'position']);
        });

        Schema::create('email_automation_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained('email_automations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->unsignedSmallInteger('next_position')->default(1);
            $table->string('status', 20)->default('active');
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'email']);
            $table->index(['status', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_automation_enrollments');
        Schema::dropIfExists('email_automation_steps');
        Schema::dropIfExists('email_automations');
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('email_events');
        Schema::dropIfExists('email_campaign_recipients');
        Schema::dropIfExists('email_campaigns');
        Schema::dropIfExists('email_segments');
        Schema::dropIfExists('email_templates');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['marketing_opt_in_at', 'marketing_opt_out_at']);
        });
    }
};
