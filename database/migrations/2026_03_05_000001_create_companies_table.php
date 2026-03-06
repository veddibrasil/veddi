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
            $table->string('subdomain')->unique()->nullable();
            $table->string('primary_color')->default('#B91C1C');
            $table->string('primary_color_dark')->default('#7F1D1D');
            $table->string('primary_color_light')->default('#DC2626');
            $table->string('secondary_color')->default('#B45309');
            $table->string('secondary_color_light')->default('#D97706');
            $table->string('accent_color')->default('#FEF3C7');
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('tagline')->nullable();
            $table->string('footer_text')->nullable();
            $table->text('abacatepay_token')->nullable();
            $table->text('abacatepay_webhook_secret')->nullable();
            $table->string('order_prefix', 10)->default('ORD');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
