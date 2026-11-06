<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telemetry_logs', function (Blueprint $table) {
            // Safely add the new column without wiping the table
            $table->string('config_gss_machine')->nullable()->after('config_gss_black');
        });
    }

    public function down(): void
    {
        Schema::table('telemetry_logs', function (Blueprint $table) {
            $table->dropColumn('config_gss_machine');
        });
    }
};