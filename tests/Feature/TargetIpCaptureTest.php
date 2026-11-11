<?php

use App\Models\TelemetryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('store endpoint saves 172.16 IP address from payload', function () {
    $payload = [
        'Plant'     => '9',
        'Line'      => '93',
        'Tier 1'    => 'L',
        'IpAddress' => '172.16.50.105',
        'Version'   => [
            'solw' => '1.0.0',
        ],
    ];

    $response = $this->postJson('/api/system-status', $payload);

    $response->assertStatus(200);

    $log = TelemetryLog::latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->plant)->toBe('9')
        ->and($log->line)->toBe('93')
        ->and($log->side)->toBe('L')
        ->and($log->ip_address)->toBe('172.16.50.105');
});

test('store endpoint falls back to socket IP when IpAddress is not in payload', function () {
    $payload = [
        'Plant'   => '9',
        'Line'    => '94',
        'Tier 1'  => 'R',
    ];

    $response = $this->postJson('/api/system-status', $payload);

    $response->assertStatus(200);

    $log = TelemetryLog::latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->plant)->toBe('9')
        ->and($log->line)->toBe('94')
        ->and($log->side)->toBe('R')
        ->and($log->ip_address)->toBe('127.0.0.1');
});

test('Deploy-AgentFleet SQL query correctly filters 172.16 inspection targets and groups by node', function () {
    // Insert multiple logs for Line 93-L with IP changes (DHCP renewal scenario)
    TelemetryLog::create([
        'plant'      => '9',
        'line'       => '93',
        'side'       => 'L',
        'ip_address' => '172.16.50.99',
        'created_at' => now()->subHours(5),
    ]);
    TelemetryLog::create([
        'plant'      => '9',
        'line'       => '93',
        'side'       => 'L',
        'ip_address' => '172.16.50.101',
        'created_at' => now()->subMinutes(10),
    ]);

    // Insert Line 93-R
    TelemetryLog::create([
        'plant'      => '9',
        'line'       => '93',
        'side'       => 'R',
        'ip_address' => '172.16.50.102',
        'created_at' => now()->subMinutes(5),
    ]);

    // Insert non-target device (e.g., non-172.16 device / loopback)
    TelemetryLog::create([
        'plant'      => '9',
        'line'       => '99',
        'side'       => 'L',
        'ip_address' => '10.0.0.22',
        'created_at' => now()->subMinutes(2),
    ]);

    // Query matching Deploy-AgentFleet.ps1: Deterministically join on latest id per node
    $query = "SELECT t.plant, t.line, t.side, t.ip_address, t.created_at as last_seen 
              FROM telemetry_logs t 
              INNER JOIN (
                  SELECT plant, line, side, MAX(id) as max_id 
                  FROM telemetry_logs 
                  WHERE (ip_address LIKE '172.16.%' OR ip_address LIKE '192.168.%') 
                    AND ip_address != '127.0.0.1' 
                    AND plant = '9' 
                  GROUP BY plant, line, side
              ) latest ON t.id = latest.max_id 
              ORDER BY CAST(t.line AS INTEGER) ASC, t.side ASC";

    $results = \Illuminate\Support\Facades\DB::select($query);

    expect(count($results))->toBe(2)
        ->and($results[0]->line)->toBe('93')
        ->and($results[0]->side)->toBe('L')
        ->and($results[0]->ip_address)->toBe('172.16.50.101') // Picked the latest IP
        ->and($results[1]->line)->toBe('93')
        ->and($results[1]->side)->toBe('R')
        ->and($results[1]->ip_address)->toBe('172.16.50.102');
});
