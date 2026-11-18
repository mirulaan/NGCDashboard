<?php

use App\Models\TelemetryLog;
use App\Models\TelemetrySsdLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('store endpoint persists full NVMe SMART telemetry fields including hex CriticalWarning', function () {
    $payload = [
        'Plant' => '9',
        'Line' => '93',
        'Tier 1' => 'LT',
        'Tier 2' => 'LB',
        'IpAddress' => '172.16.199.3',
        'SSD_Status' => [
            [
                'DiskModel' => 'KINGSTON SNV3S1000G',
                'PhysicalDiskNumber' => 0,
                'DriveLetter' => 'C:',
                'TotalSpaceGB' => 931.5,
                'RemainingSpaceGB' => 500.2,
                'TemperatureCelsius' => 58,
                'PercentageUsed' => 10,
                'CriticalWarning' => '0x00',
                'MediaErrors' => 0,
                'ErrorLogEntries' => 3,
                'UnsafeShutdowns' => 14,
                'WarningTempTime' => 0,
                'CriticalTempTime' => 0,
                'AvailableSpare' => 100,
            ],
            [
                'DiskModel' => 'KINGSTON SNV3S2000G',
                'PhysicalDiskNumber' => 1,
                'DriveLetter' => 'D:',
                'TotalSpaceGB' => 1863.0,
                'RemainingSpaceGB' => 900.0,
                'TemperatureCelsius' => 76,
                'PercentageUsed' => 25,
                'CriticalWarning' => '0x04',
                'MediaErrors' => 2,
                'ErrorLogEntries' => 150,
                'UnsafeShutdowns' => 62,
                'WarningTempTime' => 45,
                'CriticalTempTime' => 5,
                'AvailableSpare' => 85,
            ],
        ],
    ];

    $response = $this->postJson('/api/system-status', $payload);
    $response->assertStatus(200);

    $ssds = TelemetrySsdLog::orderBy('drive_letter')->get();
    expect($ssds)->toHaveCount(2);

    expect($ssds[0]->drive_letter)->toBe('C:')
        ->and($ssds[0]->critical_warning)->toBe(0)
        ->and($ssds[0]->media_errors)->toBe(0)
        ->and($ssds[0]->error_log_entries)->toBe(3)
        ->and($ssds[0]->unsafe_shutdowns)->toBe(14)
        ->and($ssds[0]->warning_temp_time)->toBe(0)
        ->and($ssds[0]->critical_temp_time)->toBe(0)
        ->and($ssds[0]->available_spare)->toBe(100);

    expect($ssds[1]->drive_letter)->toBe('D:')
        ->and($ssds[1]->critical_warning)->toBe(4)
        ->and($ssds[1]->media_errors)->toBe(2)
        ->and($ssds[1]->error_log_entries)->toBe(150)
        ->and($ssds[1]->unsafe_shutdowns)->toBe(62)
        ->and($ssds[1]->warning_temp_time)->toBe(45)
        ->and($ssds[1]->critical_temp_time)->toBe(5)
        ->and($ssds[1]->available_spare)->toBe(85);
});

test('node and fleet views surface NVMe SMART vitals and critical alerts', function () {
    $log = TelemetryLog::create([
        'plant' => '9',
        'line' => '93',
        'side' => 'L',
        'tier_1' => 'LT',
        'tier_2' => 'LB',
        'ip_address' => '172.16.199.3',
    ]);
    $log->ssdLogs()->create([
        'disk_model' => 'KINGSTON SNV3S2000G',
        'physical_disk_number' => 1,
        'drive_letter' => 'D:',
        'total_space_gb' => 1863.0,
        'remaining_space_gb' => 900.0,
        'temperature_celsius' => 76,
        'percentage_used' => 25,
        'critical_warning' => 4,
        'media_errors' => 2,
        'error_log_entries' => 150,
        'unsafe_shutdowns' => 62,
        'warning_temp_time' => 45,
        'critical_temp_time' => 5,
        'available_spare' => 85,
    ]);

    $nodeResponse = $this->get('/node/9/93/L');
    $nodeResponse->assertStatus(200)
        ->assertSee('RETIRE DRIVE')
        ->assertSee('CRIT WARN: 0x04')
        ->assertSee('150')
        ->assertSee('62')
        ->assertSee('45m');

    $fleetResponse = $this->get('/?plant=9');
    $fleetResponse->assertStatus(200)
        ->assertSee('SMART!');

    // Also verify Excel report contains SMART Health Summary table
    $reportResponse = $this->get('/node/9/93/L/report');
    $reportResponse->assertStatus(200);
    $filePath = $reportResponse->getFile()->getPathname();
    $zip = new ZipArchive();
    expect($zip->open($filePath))->toBe(true);
    $sheet1 = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($filePath);

    expect(simplexml_load_string($sheet1))->not->toBeFalse();
    expect($sheet1)
        ->toContain('NVMe SMART Health Summary (Latest)')
        ->toContain('0x04')
        ->toContain('<v>150</v>')
        ->toContain('<v>62</v>')
        ->toContain('<v>45</v>');
});

test('legacy SSD payloads without NVMe SMART fields still work cleanly', function () {
    $payload = [
        'Plant' => '9',
        'Line' => '94',
        'Tier 1' => 'RT',
        'SSD_Status' => [
            [
                'DiskModel' => 'LEGACY SSD',
                'PhysicalDiskNumber' => 0,
                'DriveLetter' => 'C:',
                'TotalSpaceGB' => 500,
                'RemainingSpaceGB' => 250,
                'TemperatureCelsius' => 42,
                'PercentageUsed' => 5,
            ],
        ],
    ];

    $this->postJson('/api/system-status', $payload)->assertStatus(200);

    $ssd = TelemetrySsdLog::first();
    expect($ssd->critical_warning)->toBeNull()
        ->and($ssd->media_errors)->toBeNull()
        ->and($ssd->unsafe_shutdowns)->toBeNull();

    $this->get('/node/9/94/R')->assertStatus(200)->assertDontSee('RETIRE DRIVE');
});
