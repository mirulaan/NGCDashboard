<?php

use App\Models\TelemetryLog;
use App\Services\PlantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('PlantService returns all defined manufacturing plants with correct topologies', function () {
    $plants = PlantService::getAllPlants();

    expect($plants)->toHaveKeys(['7', '8', '9']);
    expect($plants['7']['lines'])->toEqual(range(73, 78));
    expect($plants['8']['lines'])->toEqual(array_merge(range(83, 92), [105]));
    expect($plants['9']['lines'])->toEqual(range(93, 104));
    expect($plants['7']['sides'])->toEqual(['L', 'R']);
});

test('PlantService getTopology returns correct details for each plant and default for unknown', function () {
    $p7 = PlantService::getTopology('7');
    expect($p7['plant'])->toBe('7');
    expect($p7['lines'])->toEqual(range(73, 78));

    $unknown = PlantService::getTopology('999');
    expect($unknown['plant'])->toBe('9'); // Default plant
});

test('PlantService resolve respects manual query parameter ?plant=7', function () {
    config(['telemetry.plant' => 'auto']);

    $request = Request::create('/?plant=7');
    $resolved = PlantService::resolve($request);

    expect($resolved['plant'])->toBe('7');
    expect($resolved['lines'])->toEqual(range(73, 78));
    expect($resolved['is_auto'])->toBeFalse();
    expect(config('telemetry.plant'))->toBe('7');
    expect(config('telemetry.lines'))->toEqual(range(73, 78));
});

test('PlantService resolve respects environment variable / config override', function () {
    config(['telemetry.configured_plant' => '8', 'telemetry.plant' => '8']);

    $resolved = PlantService::resolve();

    expect($resolved['plant'])->toBe('8');
    expect($resolved['lines'])->toEqual(array_merge(range(83, 92), [105]));
    expect($resolved['is_auto'])->toBeFalse();
});

test('PlantService auto-detects plant from database telemetry logs', function () {
    config(['telemetry.plant' => 'auto']);

    // Seed telemetry logs for Plant 7
    TelemetryLog::create([
        'plant' => '7',
        'line' => '73',
        'side' => 'L',
        'created_at' => now(),
    ]);

    $resolved = PlantService::resolve();

    expect($resolved['plant'])->toBe('7');
    expect($resolved['is_auto'])->toBeTrue();
    expect($resolved['lines'])->toEqual(range(73, 78));
    expect($resolved['source'])->toContain('Database');
});

test('PlantService auto-detects higher volume plant from recent telemetry logs', function () {
    config(['telemetry.plant' => 'auto']);

    // Create 1 log for Plant 8
    TelemetryLog::create([
        'plant' => '8',
        'line' => '85',
        'side' => 'L',
        'created_at' => now()->subDay(),
    ]);

    // Create 3 logs for Plant 9
    for ($i = 0; $i < 3; $i++) {
        TelemetryLog::create([
            'plant' => '9',
            'line' => '95',
            'side' => 'L',
            'created_at' => now(),
        ]);
    }

    $resolved = PlantService::resolve();

    expect($resolved['plant'])->toBe('9');
    expect($resolved['is_auto'])->toBeTrue();
    expect($resolved['lines'])->toEqual(range(93, 104));
});

test('PlantService syncConfig accurately updates in-memory configuration', function () {
    PlantService::syncConfig('7');

    expect(config('telemetry.plant'))->toBe('7');
    expect(config('telemetry.lines'))->toEqual(range(73, 78));
    expect(config('telemetry.sides'))->toEqual(['L', 'R']);
});
