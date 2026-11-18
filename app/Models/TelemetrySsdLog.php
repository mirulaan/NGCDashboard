<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetrySsdLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'physical_disk_number' => 'integer',
        'total_space_gb' => 'float',
        'remaining_space_gb' => 'float',
        'temperature_celsius' => 'integer',
        'percentage_used' => 'integer',
        'critical_warning' => 'integer',
        'media_errors' => 'integer',
        'error_log_entries' => 'integer',
        'unsafe_shutdowns' => 'integer',
        'warning_temp_time' => 'integer',
        'critical_temp_time' => 'integer',
        'available_spare' => 'integer',
    ];

    // Define the reverse relationship: Many SSDs belong to One Log
    public function telemetryLog()
    {
        return $this->belongsTo(TelemetryLog::class);
    }
}
