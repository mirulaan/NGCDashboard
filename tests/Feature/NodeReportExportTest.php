<?php

use App\Models\TelemetryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('node view renders the Excel report export button', function () {
    $response = $this->get('/node/9/93/L');

    $response->assertStatus(200);
    $response->assertSee('Export Excel Report');
    $response->assertSee(route('node.report', ['plant' => '9', 'line' => '93', 'side' => 'L']));
});

test('node report endpoint generates valid xlsx with node info and 3 embedded 1-month plot graphs', function () {
    // Old log (> 30 days ago) — must NOT appear in 1-month charts or data table
    $oldLog = TelemetryLog::create([
        'plant' => '9',
        'line' => '93',
        'side' => 'L',
        'ip_address' => '172.16.199.3',
        'tier_1' => 'LT',
        'tier_2' => 'LB',
        'cpu_temp' => '99',
    ]);
    $oldLog->forceFill(['created_at' => now()->subDays(45)])->save();
    $oldLog->ssdLogs()->create([
        'drive_letter' => 'C:',
        'temperature_celsius' => 77,
        'percentage_used' => 5,
    ]);

    // Recent log 1 (10 days ago)
    $log1 = TelemetryLog::create([
        'plant' => '9',
        'line' => '93',
        'side' => 'L',
        'ip_address' => '172.16.199.3',
        'tier_1' => 'LT',
        'tier_2' => 'LB',
        'config_sku_mode' => '0',
        'version_solw' => 'HAIVis_20260402_GSS.solw > 1',
        'version_middleware' => '1.0.0.1299',
        'version_dimr' => '05/08/2026',
        'version_ioc' => '13/03/2026',
        'cpu_temp' => '68',
    ]);
    $log1->forceFill(['created_at' => now()->subDays(10)])->save();
    $log1->ssdLogs()->createMany([
        ['drive_letter' => 'C:', 'temperature_celsius' => 52, 'percentage_used' => 11],
        ['drive_letter' => 'D:', 'temperature_celsius' => 41, 'percentage_used' => 18],
    ]);

    // Recent log 2 (today)
    $log2 = TelemetryLog::create([
        'plant' => '9',
        'line' => '93',
        'side' => 'L',
        'ip_address' => '172.16.199.3',
        'tier_1' => 'LT',
        'tier_2' => 'LB',
        'config_sku_mode' => '0',
        'version_solw' => 'HAIVis_20260402_GSS.solw > 1',
        'version_middleware' => '1.0.0.1299',
        'version_dimr' => '05/08/2026',
        'version_ioc' => '13/03/2026',
        'cpu_temp' => '73',
    ]);
    $log2->forceFill(['created_at' => now()->subMinutes(5)])->save();
    $log2->ssdLogs()->createMany([
        ['drive_letter' => 'C:', 'temperature_celsius' => 60, 'percentage_used' => 12],
        ['drive_letter' => 'D:', 'temperature_celsius' => 45, 'percentage_used' => 19],
    ]);

    $response = $this->get('/node/9/93/L/report');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->headers->get('Content-Disposition'))->toContain('NGC_Report_Plant9_Line93_L_');

    $filePath = $response->getFile()->getPathname();
    expect(file_exists($filePath))->toBeTrue();

    $zip = new ZipArchive();
    expect($zip->open($filePath))->toBe(true);

    $entries = [
        '[Content_Types].xml',
        '_rels/.rels',
        'xl/workbook.xml',
        'xl/_rels/workbook.xml.rels',
        'xl/styles.xml',
        'xl/worksheets/sheet1.xml',
        'xl/worksheets/_rels/sheet1.xml.rels',
        'xl/worksheets/sheet2.xml',
        'xl/worksheets/sheet3.xml',
        'xl/drawings/drawing1.xml',
        'xl/drawings/_rels/drawing1.xml.rels',
        'xl/charts/chart1.xml',
        'xl/charts/chart2.xml',
        'xl/charts/chart3.xml',
    ];

    $xmlMap = [];
    foreach ($entries as $entry) {
        $content = $zip->getFromName($entry);
        expect($content)->not->toBeFalse("Missing {$entry} inside xlsx");
        expect(simplexml_load_string($content))->not->toBeFalse("Invalid XML in {$entry}");
        $xmlMap[$entry] = $content;
    }

    $zip->close();
    @unlink($filePath);

    // Sheet 1: Node & Software Information + Drawing reference
    expect($xmlMap['xl/worksheets/sheet1.xml'])
        ->toContain('Plant 9')
        ->toContain('Line 93')
        ->toContain('Side L')
        ->toContain('Running Product')
        ->toContain('Color Mode')
        ->toContain('IP Address')
        ->toContain('172.16.199.3')
        ->toContain('Tier 1 / 2 Target')
        ->toContain('LT / LB')
        ->toContain('solw')
        ->toContain('HAIVis_20260402_GSS.solw &gt; 1')
        ->toContain('MiddleWare')
        ->toContain('1.0.0.1299')
        ->toContain('DIMR Version')
        ->toContain('05/08/2026')
        ->toContain('IOC.bas')
        ->toContain('13/03/2026')
        ->toContain('<drawing r:id="rId1"/>');

    // Drawing anchors all 3 charts
    expect($xmlMap['xl/drawings/drawing1.xml'])
        ->toContain('CPU Temperature Chart')
        ->toContain('SSD Temperature Chart')
        ->toContain('SSD Wear Chart');

    // Chart 1: CPU Temp
    expect($xmlMap['xl/charts/chart1.xml'])
        ->toContain('1-Month CPU Temperature (°C)')
        ->toContain("'1-Month Data'!\$B\$5:\$B\$6")
        ->toContain('<c:v>68</c:v>')
        ->toContain('<c:v>73</c:v>')
        ->not->toContain('<c:v>99</c:v>');

    // Chart 2: SSD Temp (both C: and D: series)
    expect($xmlMap['xl/charts/chart2.xml'])
        ->toContain('1-Month SSD Temperature (°C)')
        ->toContain('SSD C: Temp (°C)')
        ->toContain('SSD D: Temp (°C)')
        ->toContain("'1-Month Data'!\$C\$5:\$C\$6")
        ->toContain("'1-Month Data'!\$E\$5:\$E\$6")
        ->toContain('<c:v>60</c:v>')
        ->toContain('<c:v>45</c:v>');

    // Chart 3: SSD Wear (both C: and D: series)
    expect($xmlMap['xl/charts/chart3.xml'])
        ->toContain('1-Month SSD Wear (%)')
        ->toContain('SSD C: Wear (%)')
        ->toContain('SSD D: Wear (%)')
        ->toContain("'1-Month Data'!\$D\$5:\$D\$6")
        ->toContain("'1-Month Data'!\$F\$5:\$F\$6")
        ->toContain('<c:v>12</c:v>')
        ->toContain('<c:v>19</c:v>');

    // Sheet 2: Backing 1-Month Data table (2 records, excluding 45-day-old record)
    expect($xmlMap['xl/worksheets/sheet2.xml'])
        ->toContain('(2 Records)')
        ->toContain('<v>68</v>')
        ->toContain('<v>73</v>')
        ->not->toContain('<v>99</v>');

    // Sheet 3: Daily Summary
    expect($xmlMap['xl/worksheets/sheet3.xml'])
        ->toContain('Daily Telemetry Summary')
        ->toContain('CPU Temp Avg (°C)')
        ->toContain('SSD C: Wear Max (%)');
});

test('node report endpoint handles node with no telemetry data gracefully', function () {
    $response = $this->get('/node/9/99/R/report');

    $response->assertStatus(200);

    $filePath = $response->getFile()->getPathname();
    $zip = new ZipArchive();
    expect($zip->open($filePath))->toBe(true);
    $sheet1 = $zip->getFromName('xl/worksheets/sheet1.xml');
    $drawing = $zip->getFromName('xl/drawings/drawing1.xml');
    $zip->close();
    @unlink($filePath);

    expect(simplexml_load_string($sheet1))->not->toBeFalse();
    expect($drawing)->toBeFalse();
    expect($sheet1)
        ->toContain('Plant 9')
        ->toContain('Line 99')
        ->toContain('Side R')
        ->toContain('No telemetry records found in the last 30 days');
});
