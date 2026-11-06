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
    Schema::create('telemetry_logs', function (Blueprint $table) {
        $table->id();
        $table->string('plant')->index();
        $table->string('line')->index();
        $table->string('side')->index();
        $table->string('tier_1')->nullable();
        $table->string('tier_2')->nullable();
        
        // Version Data
        $table->string('version_solw')->nullable();
        $table->string('version_middleware')->nullable();
        $table->string('version_dimr')->nullable();
        $table->string('version_ioc')->nullable();
        
        // Config Model Data
        $table->string('config_post')->nullable();
        $table->string('config_noglove')->nullable();
        $table->string('config_pre')->nullable();
        $table->string('config_gss')->nullable();
        $table->string('config_post_black')->nullable();
        $table->string('config_noglove_black')->nullable();
        $table->string('config_pre_black')->nullable();
        $table->string('config_gss_black')->nullable();
        
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telemetry_logs');
    }
};
