<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telemetry_logs', function (Blueprint $table) {
            $table->string('cpu_temp')->nullable()->after('config_gss_machine');
            $table->string('cpu_thermal_throttle')->nullable()->after('cpu_temp');
        });
    }

    public function down(): void
    {
        Schema::table('telemetry_logs', function (Blueprint $table) {
            $table->dropColumn(['cpu_temp', 'cpu_thermal_throttle']);
        });
    }
};