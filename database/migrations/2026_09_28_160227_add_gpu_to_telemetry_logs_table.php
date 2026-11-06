<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void {
    Schema::table('telemetry_logs', function (Blueprint $table) {
        $table->string('gpu_temp')->nullable();
        $table->string('gpu_power')->nullable();
        $table->string('gpu_clock')->nullable();
    });
}
};
