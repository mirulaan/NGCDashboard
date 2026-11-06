<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class FetchSftpBackups implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        try {
            $disk = Storage::disk('sftp');
            // Fetch all files recursively inside the BACKUP directory
            $files = $disk->allFiles('BACKUP');
            $backups = [];

            foreach ($files as $file) {
                // Match path format: BACKUP/Plant_9/93L.zip
                if (preg_match('/Plant_(\d+)\/(\d+)([a-zA-Z])\.zip$/i', $file, $matches)) {
                    $plant = $matches[1];
                    $line = $matches[2];
                    $side = strtoupper($matches[3]);
                    
                    $key = "{$plant}-{$line}-{$side}";
                    $sizeBytes = $disk->size($file);
                    $timestamp = $disk->lastModified($file);

                    // Convert to MB
                    $sizeMB = number_format($sizeBytes / 1048576, 2) . ' MB';

                    $backups[$key] = [
                        'filename' => pathinfo($file, PATHINFO_BASENAME),
                        'size' => $sizeMB,
                        'date' => Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s')
                    ];
                }
            }

            // Keep in memory (Cache) for 12 hours
            Cache::put('sftp_backups', $backups, now()->addHours(12));

        } catch (\Exception $e) {
            // Fails silently in background if SFTP is unreachable
        }
    }
}