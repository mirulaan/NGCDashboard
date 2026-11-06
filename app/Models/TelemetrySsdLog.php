<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelemetrySsdLog extends Model
{
    protected $guarded = [];

    // Define the reverse relationship: Many SSDs belong to One Log
    public function telemetryLog()
    {
        return $this->belongsTo(TelemetryLog::class);
    }
}
