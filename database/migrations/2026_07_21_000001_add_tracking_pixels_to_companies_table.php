<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('facebook_pixel_id')->nullable()->after('chat_highlights');
            $table->string('google_analytics_id')->nullable()->after('facebook_pixel_id');
            $table->string('google_ads_id')->nullable()->after('google_analytics_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['facebook_pixel_id', 'google_analytics_id', 'google_ads_id']);
        });
    }
};
