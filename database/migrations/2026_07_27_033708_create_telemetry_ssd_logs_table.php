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
    Schema::create('telemetry_ssd_logs', function (Blueprint $table) {
        $table->id();
        // This links the SSD to the specific PUSH log
        $table->foreignId('telemetry_log_id')->constrained('telemetry_logs')->onDelete('cascade');
        
        $table->string('disk_model')->nullable();
        $table->integer('physical_disk_number')->nullable();
        $table->string('drive_letter')->nullable();
        $table->float('total_space_gb')->nullable();
        $table->float('remaining_space_gb')->nullable();
        $table->integer('temperature_celsius')->nullable();
        $table->integer('percentage_used')->nullable();
        
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telemetry_ssd_logs');
    }
};
