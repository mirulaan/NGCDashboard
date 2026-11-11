<?php

use App\Models\TelemetryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('Fleet view loads with auto-detected plant', function () {
    TelemetryLog::create([
        'plant' => '7',
        'line' => '73',
        'side' => 'L',
    ]);

    $response = $this->get('/');
    $response->assertStatus(200);
    $response->assertSee('Plant 7 Fleet Status');
    $response->assertSee('Monitoring 6 Lines');
});

test('Fleet view allows switching to Plant 8 via query string', function () {
    $response = $this->get('/?plant=8');
    $response->assertStatus(200);
    $response->assertSee('Plant 8 Fleet Status');
    $response->assertSee('Monitoring 11 Lines');
});

test('Fleet view allows switching to Plant 9 via query string', function () {
    $response = $this->get('/?plant=9');
    $response->assertStatus(200);
    $response->assertSee('Plant 9 Fleet Status');
    $response->assertSee('Monitoring 12 Lines');
});
