<?php

$plants = [
    '7' => [
        'plant' => '7',
        'name'  => 'Plant 7',
        'lines' => range(73, 78),
        'sides' => ['L', 'R'],
    ],
    '8' => [
        'plant' => '8',
        'name'  => 'Plant 8',
        'lines' => array_merge(range(83, 92), [105]),
        'sides' => ['L', 'R'],
    ],
    '9' => [
        'plant' => '9',
        'name'  => 'Plant 9',
        'lines' => range(93, 104),
        'sides' => ['L', 'R'],
    ],
];

$configuredPlant = env('TELEMETRY_PLANT', 'auto');
$defaultPlant = '9';
$initialPlantKey = ($configuredPlant !== 'auto' && isset($plants[$configuredPlant]))
    ? $configuredPlant
    : $defaultPlant;

$initialPlantConfig = $plants[$initialPlantKey];

return [
    /*
    |--------------------------------------------------------------------------
    | Active Plant Selection & Auto-Detection
    |--------------------------------------------------------------------------
    | Options:
    |   - 'auto' : Automatically detect plant based on incoming telemetry logs,
    |              server hostname (e.g. Plant7, P8, Plant-9), or subnet IP.
    |   - '7'    : Fixed to Plant 7 (Lines 73 - 78)
    |   - '8'    : Fixed to Plant 8 (Lines 83 - 92, 105)
    |   - '9'    : Fixed to Plant 9 (Lines 93 - 104)
    |
    | Set in .env via TELEMETRY_PLANT=auto (or 7, 8, 9)
    */
    'configured_plant' => $configuredPlant,
    'plant' => $configuredPlant,
    'default_plant' => $defaultPlant,

    /*
    |--------------------------------------------------------------------------
    | All Manufacturing Plant Topologies
    |--------------------------------------------------------------------------
    */
    'plants' => $plants,

    /*
    |--------------------------------------------------------------------------
    | Subnet Mapping for Auto-Detection
    |--------------------------------------------------------------------------
    */
    'subnet_map' => [
        '7' => ['172.16.7.', '172.16.70.', '192.168.7.'],
        '8' => ['172.16.8.', '172.16.80.', '192.168.8.'],
        '9' => ['172.16.9.', '172.16.90.', '192.168.9.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backward Compatibility Defaults
    |--------------------------------------------------------------------------
    */
    'lines' => $initialPlantConfig['lines'],
    'sides' => $initialPlantConfig['sides'],
];
