<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetryLog extends Model
{
    protected $fillable = [
        'plant', 'line', 'side', 'tier_1', 'tier_2',
        'version_solw', 'version_middleware', 'version_dimr', 'version_ioc',
        'config_sku_mode', // <-- Add this new column here
        'config_post', 'config_noglove', 'config_pre', 'config_gss',
        'config_post_black', 'config_noglove_black', 'config_pre_black', 'config_gss_black',
        'config_gss_machine',
        'cpu_temp', 'cpu_thermal_throttle',
        'gpu_temp', 'gpu_power', 'gpu_clock', // <-- Add these new columns here
    ];

    // Define the relationship: One Log has Many SSDs
    public function ssdLogs()
    {
        return $this->hasMany(TelemetrySsdLog::class);
    }
}
