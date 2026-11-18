<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('telemetry_ssd_logs', function (Blueprint $table) {
            $table->unsignedSmallInteger('critical_warning')->nullable()->after('percentage_used');
            $table->unsignedBigInteger('media_errors')->nullable()->after('critical_warning');
            $table->unsignedBigInteger('error_log_entries')->nullable()->after('media_errors');
            $table->unsignedBigInteger('unsafe_shutdowns')->nullable()->after('error_log_entries');
            $table->unsignedBigInteger('warning_temp_time')->nullable()->after('unsafe_shutdowns');
            $table->unsignedBigInteger('critical_temp_time')->nullable()->after('warning_temp_time');
            $table->unsignedTinyInteger('available_spare')->nullable()->after('critical_temp_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telemetry_ssd_logs', function (Blueprint $table) {
            $table->dropColumn([
                'critical_warning',
                'media_errors',
                'error_log_entries',
                'unsafe_shutdowns',
                'warning_temp_time',
                'critical_temp_time',
                'available_spare',
            ]);
        });
    }
};
