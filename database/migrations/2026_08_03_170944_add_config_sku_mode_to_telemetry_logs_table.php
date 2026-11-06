<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telemetry_logs', function (Blueprint $table) {
            // Add the SkuMode column right before the other config models
            $table->string('config_sku_mode')->nullable()->after('version_ioc');
        });
    }

    public function down(): void
    {
        Schema::table('telemetry_logs', function (Blueprint $table) {
            $table->dropColumn('config_sku_mode');
        });
    }
};