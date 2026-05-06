<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('pix_fee_absorbed_by_company')->default(true);
            $table->boolean('card_fee_absorbed_by_company')->default(true);
            $table->string('subdomain')->unique()->nullable();
            $table->string('primary_color')->default('#5c347f');
            $table->string('primary_color_dark')->default('#19273c');
            $table->string('primary_color_light')->default('#5c347f');
            $table->string('secondary_color')->default('#e36831');
            $table->string('secondary_color_light')->default('#D97706');
            $table->string('accent_color')->default('#cad1d8');
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('tagline')->nullable();
            $table->string('footer_text')->nullable();
            $table->json('chat_highlights')->nullable();
            $table->string('order_prefix', 10)->default('ORD');
            $table->boolean('active')->default(true);
            $table->string('plan', 30)->nullable();
            $table->string('pending_plan', 30)->nullable();
            $table->enum('status', ['PENDING_PAYMENT', 'ACTIVE', 'BLOCKED'])->default('ACTIVE');
            $table->date('overdue_since')->nullable();
            $table->string('owner_cpf_cnpj', 20)->nullable();
            $table->string('asaas_customer_id')->nullable()->index();
            $table->string('asaas_subscription_id')->nullable()->index();
            $table->string('asaas_setup_charge_id')->nullable()->index();
            $table->timestamp('setup_fee_paid_at')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->foreignId('terms_accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('terms_version', 20)->nullable();
            $table->string('subscription_payment_method', 20)->default('PIX');
            $table->text('asaas_setup_invoice_url')->nullable();
            $table->text('asaas_setup_pix_qr_code')->nullable();
            $table->text('asaas_setup_pix_copy_paste')->nullable();
            $table->text('asaas_setup_bank_slip_url')->nullable();
            $table->string('default_payout_type')->nullable();
            $table->string('default_pix_key')->nullable();
            $table->string('default_pix_key_type')->nullable();
            $table->string('default_bank_code')->nullable();
            $table->string('default_bank_agency')->nullable();
            $table->string('default_bank_account')->nullable();
            $table->string('default_bank_account_digit')->nullable();
            $table->string('default_bank_account_type')->nullable();
            $table->string('default_bank_owner_cpf_cnpj')->nullable();
            $table->string('default_bank_owner_name')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address');
            $table->string('city');
            $table->string('phone', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->time('opens_at')->default('08:00');
            $table->time('closes_at')->default('20:00');
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 100)->default('company_admin');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 20);
            $table->string('number', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('tax_id', 14)->nullable();
            $table->string('address')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('city')->nullable();
            $table->string('cep', 9)->nullable();
            $table->string('complement', 100)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'phone']);
            $table->unique(['company_id', 'email']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 8, 2);
            $table->string('image_path')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('total_qty');
            $table->boolean('fixed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_option_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('additional_price', 8, 2)->default(0.00);
            $table->unsignedInteger('default_qty')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->enum('type', ['percentage', 'fixed', 'free_delivery', 'free_product']);
            $table->decimal('discount_value', 8, 2)->nullable();
            $table->foreignId('free_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->enum('scope', ['order', 'category', 'product'])->default('order');
            $table->json('scope_ids')->nullable();
            $table->decimal('minimum_order_value', 8, 2)->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_customer')->nullable()->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('branch_product', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->boolean('available')->default(true);
            $table->integer('quantity')->default(0);
            $table->integer('min_quantity')->default(0);
            $table->boolean('track_stock')->default(false);
            $table->primary(['branch_id', 'product_id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('order_number')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->decimal('subtotal', 8, 2);
            $table->decimal('total', 8, 2);
            $table->decimal('delivery_fee', 8, 2)->default(0);
            $table->decimal('discount', 8, 2)->default(0);
            $table->decimal('fee', 8, 2)->nullable();
            $table->decimal('net_value', 8, 2)->nullable();
            $table->enum('status', [
                'pending',
                'awaiting_payment',
                'paid',
                'preparing',
                'ready',
                'delivered',
                'cancelled',
                'refunded',
                'scheduled',
            ])->default('pending');
            $table->string('payment_method')->nullable();
            $table->enum('order_type', ['delivery', 'pickup'])->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('fee_billed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'orders_company_created_at_idx');
            $table->index(['branch_id', 'status'], 'orders_branch_status_idx');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->decimal('unit_price', 8, 2);
            $table->integer('quantity');
            $table->decimal('subtotal', 8, 2);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('asaas_payment_id')->nullable()->index();
            $table->string('stark_payment_id')->nullable()->index();
            $table->string('payment_gateway')->default('asaas');
            $table->text('pix_qr_code')->nullable();
            $table->text('pix_copy_paste')->nullable();
            $table->decimal('amount', 8, 2);
            $table->decimal('pix_fee', 10, 2)->default(0);
            $table->decimal('original_amount', 10, 2)->nullable();
            $table->decimal('card_fee', 10, 2)->nullable();
            $table->decimal('card_fee_rate', 8, 6)->nullable();
            $table->tinyInteger('installments')->nullable();
            $table->tinyInteger('anticipation_days')->nullable();
            $table->enum('status', ['pending', 'paid', 'expired', 'failed', 'refunded'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->json('webhook_payload')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('payment_token')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('delivery_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->enum('fee_type', ['flat', 'neighborhood', 'distance'])->default('flat');
            $table->decimal('flat_fee', 8, 2)->default(0);
            $table->decimal('minimum_order_value', 8, 2)->default(0);
            $table->decimal('free_delivery_above', 8, 2)->nullable();
            $table->decimal('branch_latitude', 10, 7)->nullable();
            $table->decimal('branch_longitude', 10, 7)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('delivery_neighborhoods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_setting_id')->constrained()->cascadeOnDelete();
            $table->string('neighborhood', 100);
            $table->decimal('fee', 8, 2);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('delivery_distance_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_setting_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_km', 5, 2)->default(0);
            $table->decimal('max_km', 5, 2)->nullable();
            $table->decimal('fee', 8, 2);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['slug', 'company_id']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('group', 60)->index();
            $table->string('label', 150);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('granted')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'company_id', 'permission_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('quantity');
            $table->integer('quantity_before');
            $table->integer('quantity_after');
            $table->string('type', 50);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'product_id']);
            $table->index(['company_id', 'created_at']);
            $table->index('type');
            $table->index(['branch_id', 'product_id', 'created_at'], 'stock_movements_branch_product_created_idx');
            $table->index(['company_id', 'type'], 'stock_movements_company_type_idx');
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->decimal('discount_applied', 8, 2);
            $table->timestamps();

            $table->index(['coupon_id', 'customer_id'], 'coupon_usages_coupon_customer_idx');
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('company_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'read_at']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('asaas_subscription_id')->unique();
            $table->string('plan', 30)->nullable();
            $table->enum('status', ['active', 'inactive', 'overdue', 'cancelled'])->default('active');
            $table->decimal('amount', 8, 2);
            $table->enum('billing_cycle', ['MONTHLY', 'YEARLY'])->default('MONTHLY');
            $table->date('next_due_date')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamps();
        });

        Schema::create('company_wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['credit', 'fee', 'withdrawal', 'refund', 'anticipation_fee', 'pix_fee', 'card_fee']);
            $table->decimal('amount', 10, 2);
            $table->string('description');
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        Schema::create('company_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->decimal('pix_fee', 10, 2)->default(0);
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])->default('pending');
            $table->enum('payout_type', ['PIX', 'TED']);
            $table->string('pix_key')->nullable();
            $table->string('pix_key_type')->nullable();
            $table->string('bank_code')->nullable();
            $table->string('bank_agency')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('bank_account_digit')->nullable();
            $table->string('bank_account_type')->nullable();
            $table->string('bank_owner_cpf_cnpj')->nullable();
            $table->string('bank_owner_name')->nullable();
            $table->string('asaas_transfer_id')->nullable();
            $table->json('asaas_response')->nullable();
            $table->string('stark_transfer_id')->nullable();
            $table->json('stark_response')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('company_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['pix', 'cartao', 'boleto']);
            $table->enum('status', ['pending', 'confirmed', 'released', 'withdrawn', 'refunded', 'chargeback'])
                ->default('pending');
            $table->decimal('value', 10, 2);
            $table->decimal('net_value', 10, 2);
            $table->date('payment_date');
            $table->date('release_date');
            $table->boolean('withdrawn')->default(false);
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawal_id')
                ->nullable()
                ->constrained('company_withdrawals')
                ->nullOnDelete();
            $table->boolean('is_anticipated')->default(false);
            $table->decimal('anticipation_fee', 10, 2)->default(0);
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'release_date'], 'ct_company_status_release_idx');
            $table->index(['company_id', 'withdrawn'], 'ct_company_withdrawn_idx');
        });

        Schema::create('company_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('total_balance', 10, 2)->default(0);
            $table->decimal('blocked_balance', 10, 2)->default(0);
            $table->decimal('available_balance', 10, 2)->default(0);
            $table->decimal('withdrawn_balance', 10, 2)->default(0);
            $table->decimal('reserve_balance', 10, 2)->default(0);
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('card_rate_1x', 5, 4)->default(0.0299);
            $table->decimal('anticipation_rate_d2', 5, 4)->default(0.0299);
            $table->decimal('anticipation_rate_d7', 5, 4)->default(0.0249);
            $table->decimal('anticipation_rate_d15', 5, 4)->default(0.0199);
            $table->decimal('anticipation_rate_d30', 5, 4)->default(0.0000);
            $table->decimal('system_fee_rate', 5, 4)->default(0.0000);
            $table->unsignedTinyInteger('default_anticipation_days')->default(15);
            $table->timestamps();
        });

        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('notify_on_new_order')->default(true);
            $table->boolean('notify_on_awaiting_payment')->default(true);
            $table->boolean('notify_on_paid')->default(true);
            $table->boolean('notify_on_preparing')->default(true);
            $table->boolean('notify_on_ready')->default(true);
            $table->boolean('notify_on_delivered')->default(true);
            $table->boolean('notify_on_cancelled')->default(true);
            $table->boolean('notify_on_admin_message')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_settings');
        Schema::dropIfExists('payment_settings');
        Schema::dropIfExists('company_balances');
        Schema::dropIfExists('company_transactions');
        Schema::dropIfExists('company_withdrawals');
        Schema::dropIfExists('company_wallet_entries');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('company_notifications');
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('delivery_distance_tiers');
        Schema::dropIfExists('delivery_neighborhoods');
        Schema::dropIfExists('delivery_settings');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('branch_product');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('product_option_groups');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('companies');
    }
};
