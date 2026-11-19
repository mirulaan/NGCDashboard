<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class NodeReportExcelService
{
    private const SERIES_COLORS = [
        '4F46E5', // Indigo
        '06B6D4', // Cyan
        '10B981', // Emerald
        'F59E0B', // Amber
        'EC4899', // Pink
        '8B5CF6', // Violet
    ];

    /**
     * Generate a styled OpenXML (.xlsx) telemetry report with embedded native Excel plot graphs.
     *
     * Workbook structure:
     *  - Sheet 1 ("Node Report"): Node & Software Information + 3 embedded line plot graphs
     *                             (1-Month CPU Temp, 1-Month SSD Temp, 1-Month SSD Wear)
     *  - Sheet 2 ("1-Month Data"): Raw 30-day telemetry table backing the charts
     *  - Sheet 3 ("Daily Summary"): 30-day daily Min/Avg/Max rollup table
     *
     * @param  array<string, mixed>  $nodeMeta
     * @param  array<int, array<string, mixed>>  $rows  Chronological rows: ['timestamp' => string, 'cpu_temp' => ?float, 'ssds' => [drive => ['temp' => ?float, 'wear' => ?float]]]
     * @param  array<int, string>  $drives  Sorted unique drive labels (e.g. ['C:', 'D:'])
     * @return string  Absolute path to the generated temporary .xlsx file
     */
    public function generate(array $nodeMeta, array $rows, array $drives, Carbon $periodStart, Carbon $periodEnd): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension (ZipArchive) is required to generate Excel (.xlsx) reports.');
        }

        $reportDir = storage_path('app/private/reports');
        File::ensureDirectoryExists($reportDir);

        $tempPath = $reportDir.DIRECTORY_SEPARATOR.'node_report_'.uniqid('', true).'.xlsx';

        $hasChartData = ! empty($rows);
        $effectiveDrives = ! empty($drives) ? $drives : [null];

        $zip = new ZipArchive();
        $result = $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new RuntimeException("Unable to create Excel archive at {$tempPath} (ZipArchive code {$result}).");
        }

        $generatedIso = now()->utc()->format('Y-m-d\TH:i:s\Z');

        $zip->addFromString('[Content_Types].xml', $this->buildContentTypesXml($hasChartData));
        $zip->addFromString('_rels/.rels', $this->buildRootRelsXml());
        $zip->addFromString('docProps/app.xml', $this->buildAppPropsXml());
        $zip->addFromString('docProps/core.xml', $this->buildCorePropsXml($nodeMeta, $generatedIso));
        $zip->addFromString('xl/workbook.xml', $this->buildWorkbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->buildStylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->buildOverviewSheetXml($nodeMeta, count($rows), $hasChartData, $periodStart, $periodEnd));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->buildDataSheetXml($nodeMeta, $rows, $effectiveDrives, $periodStart, $periodEnd));
        $zip->addFromString('xl/worksheets/sheet3.xml', $this->buildDailySummarySheetXml($nodeMeta, $rows, $effectiveDrives, $periodStart, $periodEnd));

        if ($hasChartData) {
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $this->buildSheet1RelsXml());
            $zip->addFromString('xl/drawings/drawing1.xml', $this->buildDrawingXml());
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $this->buildDrawingRelsXml());

            $timestamps = array_map(fn (array $r): string => (string) $r['timestamp'], $rows);
            $lastDataRow = 4 + count($rows);

            // Chart 1: 1-Month CPU Temperature (°C)
            $cpuSeries = [[
                'name' => 'CPU Temp (°C)',
                'nameRef' => "'1-Month Data'!\$B\$4",
                'valRef' => "'1-Month Data'!\$B\$5:\$B\${$lastDataRow}",
                'values' => array_map(fn (array $r): ?float => $r['cpu_temp'], $rows),
                'color' => 'EF4444', // Rose/Red for CPU thermal
            ]];
            $zip->addFromString(
                'xl/charts/chart1.xml',
                $this->buildLineChartXml(
                    title: '1-Month CPU Temperature (°C)',
                    yAxisTitle: 'Temperature (°C)',
                    yAxisFormat: '0',
                    catRef: "'1-Month Data'!\$A\$5:\$A\${$lastDataRow}",
                    categories: $timestamps,
                    seriesList: $cpuSeries,
                    axIdBase: 1000,
                    yMinZero: false
                )
            );
            unset($cpuSeries);

            // Chart 2: 1-Month SSD Temperature (°C)
            $ssdTempSeries = [];
            $colIdx = 3; // Column C starts SSD pairs (Temp, Wear)
            foreach ($effectiveDrives as $idx => $drive) {
                $tempCol = $this->colLetter($colIdx);
                $label = $drive === null ? 'SSD Temp (°C)' : "SSD {$drive} Temp (°C)";
                $ssdTempSeries[] = [
                    'name' => $label,
                    'nameRef' => "'1-Month Data'!\${$tempCol}\$4",
                    'valRef' => "'1-Month Data'!\${$tempCol}\$5:\${$tempCol}\${$lastDataRow}",
                    'values' => array_map(
                        fn (array $r): ?float => ($drive !== null && isset($r['ssds'][$drive])) ? $r['ssds'][$drive]['temp'] : null,
                        $rows
                    ),
                    'color' => self::SERIES_COLORS[$idx % count(self::SERIES_COLORS)],
                ];
                $colIdx += 2;
            }
            $zip->addFromString(
                'xl/charts/chart2.xml',
                $this->buildLineChartXml(
                    title: '1-Month SSD Temperature (°C)',
                    yAxisTitle: 'Temperature (°C)',
                    yAxisFormat: '0',
                    catRef: "'1-Month Data'!\$A\$5:\$A\${$lastDataRow}",
                    categories: $timestamps,
                    seriesList: $ssdTempSeries,
                    axIdBase: 2000,
                    yMinZero: false
                )
            );
            unset($ssdTempSeries);

            // Chart 3: 1-Month SSD Wear (%)
            $ssdWearSeries = [];
            $colIdx = 4; // Column D is first SSD Wear column
            foreach ($effectiveDrives as $idx => $drive) {
                $wearCol = $this->colLetter($colIdx);
                $label = $drive === null ? 'SSD Wear (%)' : "SSD {$drive} Wear (%)";
                $ssdWearSeries[] = [
                    'name' => $label,
                    'nameRef' => "'1-Month Data'!\${$wearCol}\$4",
                    'valRef' => "'1-Month Data'!\${$wearCol}\$5:\${$wearCol}\${$lastDataRow}",
                    'values' => array_map(
                        fn (array $r): ?float => ($drive !== null && isset($r['ssds'][$drive])) ? $r['ssds'][$drive]['wear'] : null,
                        $rows
                    ),
                    'color' => self::SERIES_COLORS[$idx % count(self::SERIES_COLORS)],
                ];
                $colIdx += 2;
            }
            $zip->addFromString(
                'xl/charts/chart3.xml',
                $this->buildLineChartXml(
                    title: '1-Month SSD Wear (%)',
                    yAxisTitle: 'Wear (%)',
                    yAxisFormat: '0"%"',
                    catRef: "'1-Month Data'!\$A\$5:\$A\${$lastDataRow}",
                    categories: $timestamps,
                    seriesList: $ssdWearSeries,
                    axIdBase: 3000,
                    yMinZero: true
                )
            );
            unset($ssdWearSeries, $timestamps);
        }

        $zip->close();

        return $tempPath;
    }

    /**
     * Sheet 1 ("Node Report"):
     * Executive dashboard with Node & Software Information (A4:F15) and 3 embedded Excel plot graphs:
     *  - Chart 1 (CPU Temp): H4:P18
     *  - Chart 2 (SSD Temp): A20:H36
     *  - Chart 3 (SSD Wear): I20:P36
     */
    private function buildOverviewSheetXml(array $nodeMeta, int $recordCount, bool $hasChartData, Carbon $periodStart, Carbon $periodEnd): string
    {
        $plant = (string) ($nodeMeta['plant'] ?? '-');
        $line = (string) ($nodeMeta['line'] ?? '-');
        $side = (string) ($nodeMeta['side'] ?? '-');

        $infoRows = [
            ['Plant', "Plant {$plant}"],
            ['Line', "Line {$line}"],
            ['Side', "Side {$side}"],
            ['Running Product', (string) ($nodeMeta['running_product'] ?? '-')],
            ['IP Address', (string) ($nodeMeta['ip_address'] ?? '-')],
            ['Tier 1 / 2 Target', (string) ($nodeMeta['tier_target'] ?? '- / -')],
            ['solw', (string) ($nodeMeta['solw'] ?? '-')],
            ['MiddleWare', (string) ($nodeMeta['middleware'] ?? '-')],
            ['DIMR Version', (string) ($nodeMeta['dimr'] ?? '-')],
            ['IOC.bas', (string) ($nodeMeta['ioc'] ?? '-')],
            ['Last Telemetry Seen', (string) ($nodeMeta['last_seen'] ?? '-')],
        ];

        $out = [];
        $out[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $out[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        $out[] = '<sheetViews><sheetView tabSelected="1" workbookViewId="0"/></sheetViews>';

        // 16 columns (A..P): A..F for Node Info, G spacer, H..P for Chart 1; A..H & I..P for Charts 2 & 3
        $out[] = '<cols>';
        $out[] = '<col min="1" max="1" width="16" customWidth="1"/>';
        $out[] = '<col min="2" max="2" width="26" customWidth="1"/>';
        $out[] = '<col min="3" max="4" width="12" customWidth="1"/>';
        $out[] = '<col min="5" max="5" width="16" customWidth="1"/>';
        $out[] = '<col min="6" max="6" width="14" customWidth="1"/>';
        $out[] = '<col min="7" max="8" width="17" customWidth="1"/>';
        $out[] = '<col min="9" max="10" width="20" customWidth="1"/>';
        $out[] = '<col min="11" max="11" width="16" customWidth="1"/>';
        $out[] = '<col min="12" max="16" width="12" customWidth="1"/>';
        $out[] = '</cols>';

        $out[] = '<sheetData>';
        $merges = [];

        // Row 1: Title Banner (A1:P1)
        $title = "NGC Dashboard - Node Telemetry Report (Plant {$plant} | Line {$line} | Side {$side})";
        $out[] = $this->buildMergedBannerRow(1, $title, 16, 1, 28);
        $merges[] = 'A1:P1';

        // Row 2: Subtitle (A2:P2)
        $subtitle = sprintf(
            'Period: %s to %s (Last 30 Days - %d Records) | Generated: %s',
            $periodStart->format('Y-m-d H:i'),
            $periodEnd->format('Y-m-d H:i'),
            $recordCount,
            $periodEnd->format('Y-m-d H:i:s')
        );
        $out[] = $this->buildMergedBannerRow(2, $subtitle, 16, 2, 19);
        $merges[] = 'A2:P2';

        // Row 3: Spacer
        $out[] = '<row r="3" ht="8" customHeight="1"/>';

        // Row 4: Section Header for Node Info (A4:F4)
        $out[] = $this->buildMergedBannerRow(4, 'Node & Software Information', 6, 3, 22);
        $merges[] = 'A4:F4';

        // Rows 5..15: Node Info Key-Value Pairs (A = label, B..F merged = value)
        $rowIdx = 5;
        foreach ($infoRows as [$label, $value]) {
            $cells = $this->stringCell("A{$rowIdx}", $label, 4);
            $cells .= $this->stringCell("B{$rowIdx}", $value !== '' ? $value : '-', 5);
            for ($c = 3; $c <= 6; $c++) {
                $cells .= '<c r="'.$this->colLetter($c).$rowIdx.'" s="5"/>';
            }
            $out[] = '<row r="'.$rowIdx.'" ht="20" customHeight="1">'.$cells.'</row>';
            $merges[] = "B{$rowIdx}:F{$rowIdx}";
            $rowIdx++;
        }

        // Row 17: Quick note if no data is available to plot
        if (! $hasChartData) {
            $out[] = '<row r="16" ht="10" customHeight="1"/>';
            $cells = $this->stringCell('A17', 'No telemetry records found in the last 30 days — plot graphs will appear once telemetry data is available.', 15);
            for ($c = 2; $c <= 16; $c++) {
                $cells .= '<c r="'.$this->colLetter($c).'17" s="15"/>';
            }
            $out[] = '<row r="17" ht="24" customHeight="1">'.$cells.'</row>';
            $merges[] = 'A17:P17';
        }

        // NVMe SMART Health Summary Table (placed below the charts when chart data exists, or below notice row)
        $ssdSmartList = $nodeMeta['ssd_smart'] ?? [];
        if (! empty($ssdSmartList)) {
            $smartStartRow = $hasChartData ? 38 : 19;
            $out[] = $this->buildMergedBannerRow($smartStartRow, 'NVMe SMART Health Summary (Latest)', 11, 3, 22);
            $merges[] = "A{$smartStartRow}:K{$smartStartRow}";

            $smartHeaders = [
                'Drive',
                'Disk Model',
                'Temp (°C)',
                'Wear (%)',
                'Critical Warning',
                'Media Errors',
                'Error Log Entries',
                'Unsafe Shutdowns',
                'Warn Temp Time (min)',
                'Crit Temp Time (min)',
                'Health Status',
            ];
            $hdrRow = $smartStartRow + 1;
            $hdrCells = '';
            foreach ($smartHeaders as $idx => $hText) {
                $hdrCells .= $this->stringCell($this->colLetter($idx + 1).$hdrRow, $hText, 6);
            }
            $out[] = '<row r="'.$hdrRow.'" ht="24" customHeight="1">'.$hdrCells.'</row>';

            $sRow = $hdrRow + 1;
            foreach ($ssdSmartList as $idx => $ssd) {
                $isOdd = ($idx % 2) === 1;
                $tStyle = $isOdd ? 8 : 7;
                $nStyle = $isOdd ? 10 : 9;
                $pStyle = $isOdd ? 14 : 13;

                $cw = isset($ssd['CriticalWarning']) && $ssd['CriticalWarning'] !== null ? (int) $ssd['CriticalWarning'] : null;
                $cwText = $cw !== null ? sprintf('0x%02X', $cw) : '-';

                $temp = isset($ssd['TemperatureCelsius']) && $ssd['TemperatureCelsius'] !== null ? (int) $ssd['TemperatureCelsius'] : null;
                $wear = isset($ssd['PercentageUsed']) && $ssd['PercentageUsed'] !== null ? (int) $ssd['PercentageUsed'] : null;
                $mediaErr = isset($ssd['MediaErrors']) && $ssd['MediaErrors'] !== null ? (int) $ssd['MediaErrors'] : null;
                $errLogs = isset($ssd['ErrorLogEntries']) && $ssd['ErrorLogEntries'] !== null ? (int) $ssd['ErrorLogEntries'] : null;
                $unsafeOff = isset($ssd['UnsafeShutdowns']) && $ssd['UnsafeShutdowns'] !== null ? (int) $ssd['UnsafeShutdowns'] : null;
                $warnTempTime = isset($ssd['WarningTempTime']) && $ssd['WarningTempTime'] !== null ? (int) $ssd['WarningTempTime'] : null;
                $critTempTime = isset($ssd['CriticalTempTime']) && $ssd['CriticalTempTime'] !== null ? (int) $ssd['CriticalTempTime'] : null;

                // Evaluate Drive Health Status
                $isCritical = ($cw !== null && $cw > 0)
                    || ($mediaErr !== null && $mediaErr > 0)
                    || ($temp !== null && $temp >= 70)
                    || ($wear !== null && $wear > 80)
                    || ($errLogs !== null && $errLogs >= 100)
                    || ($unsafeOff !== null && $unsafeOff >= 50)
                    || ($critTempTime !== null && $critTempTime > 0);

                $isWarning = ($wear !== null && $wear > 50)
                    || ($temp !== null && $temp > 45)
                    || ($errLogs !== null && $errLogs > 0)
                    || ($unsafeOff !== null && $unsafeOff >= 20)
                    || ($warnTempTime !== null && $warnTempTime > 0);

                if ($isCritical) {
                    $statusText = 'CRITICAL';
                    $statusStyle = 18;
                } elseif ($isWarning) {
                    $statusText = 'NOT OK (WARN)';
                    $statusStyle = 17;
                } else {
                    $statusText = 'OK';
                    $statusStyle = 16;
                }

                // Temp cell
                if ($temp !== null) {
                    $tempStyle = $temp >= 70 ? 21 : ($temp > 45 ? 20 : $nStyle);
                    $tempCell = $this->numberCell("C{$sRow}", $temp, $tempStyle);
                } else {
                    $tempCell = $this->stringCell("C{$sRow}", '-', $tStyle);
                }

                // Wear cell
                if ($wear !== null) {
                    $wearStyle = $wear > 80 ? 24 : ($wear > 50 ? 23 : $pStyle);
                    $wearCell = $this->numberCell("D{$sRow}", $wear, $wearStyle);
                } else {
                    $wearCell = $this->stringCell("D{$sRow}", '-', $tStyle);
                }

                // Critical Warning cell
                if ($cw !== null) {
                    $cwStyle = $cw > 0 ? 18 : 16;
                    $cwCell = $this->stringCell("E{$sRow}", $cwText, $cwStyle);
                } else {
                    $cwCell = $this->stringCell("E{$sRow}", '-', $tStyle);
                }

                // Media Errors cell
                if ($mediaErr !== null) {
                    $mediaStyle = $mediaErr > 0 ? 21 : $nStyle;
                    $mediaCell = $this->numberCell("F{$sRow}", $mediaErr, $mediaStyle);
                } else {
                    $mediaCell = $this->stringCell("F{$sRow}", '-', $tStyle);
                }

                // Error Log Entries cell
                if ($errLogs !== null) {
                    $errStyle = $errLogs >= 100 ? 21 : ($errLogs > 0 ? 20 : $nStyle);
                    $errCell = $this->numberCell("G{$sRow}", $errLogs, $errStyle);
                } else {
                    $errCell = $this->stringCell("G{$sRow}", '-', $tStyle);
                }

                // Unsafe Shutdowns cell
                if ($unsafeOff !== null) {
                    $unsafeStyle = $unsafeOff >= 50 ? 21 : ($unsafeOff >= 20 ? 20 : $nStyle);
                    $unsafeCell = $this->numberCell("H{$sRow}", $unsafeOff, $unsafeStyle);
                } else {
                    $unsafeCell = $this->stringCell("H{$sRow}", '-', $tStyle);
                }

                // Warn Temp Time cell
                if ($warnTempTime !== null) {
                    $warnTStyle = $warnTempTime > 0 ? 20 : $nStyle;
                    $warnTCell = $this->numberCell("I{$sRow}", $warnTempTime, $warnTStyle);
                } else {
                    $warnTCell = $this->stringCell("I{$sRow}", '-', $tStyle);
                }

                // Crit Temp Time cell
                if ($critTempTime !== null) {
                    $critTStyle = $critTempTime > 0 ? 21 : $nStyle;
                    $critTCell = $this->numberCell("J{$sRow}", $critTempTime, $critTStyle);
                } else {
                    $critTCell = $this->stringCell("J{$sRow}", '-', $tStyle);
                }

                // Health Status cell
                $statusCell = $this->stringCell("K{$sRow}", $statusText, $statusStyle);

                $cells = $this->stringCell("A{$sRow}", (string) ($ssd['DriveLetter'] ?? '-'), $tStyle);
                $cells .= $this->stringCell("B{$sRow}", (string) ($ssd['DiskModel'] ?? '-'), $tStyle);
                $cells .= $tempCell;
                $cells .= $wearCell;
                $cells .= $cwCell;
                $cells .= $mediaCell;
                $cells .= $errCell;
                $cells .= $unsafeCell;
                $cells .= $warnTCell;
                $cells .= $critTCell;
                $cells .= $statusCell;

                $out[] = '<row r="'.$sRow.'" ht="20" customHeight="1">'.$cells.'</row>';
                $sRow++;
            }

            // Spacer row before Legend
            $out[] = '<row r="'.$sRow.'" ht="14" customHeight="1"/>';
            $sRow++;

            // Legend Banner
            $legendStartRow = $sRow;
            $out[] = $this->buildMergedBannerRow($legendStartRow, 'NVMe SMART Health Threshold Legend & Status Guide', 11, 3, 22);
            $merges[] = "A{$legendStartRow}:K{$legendStartRow}";
            $sRow++;

            // Legend Column Headers
            $lHdrRow = $sRow;
            $lHdrCells = $this->stringCell("A{$lHdrRow}", 'Metric / SMART Attribute', 6);
            $lHdrCells .= '<c r="B'.$lHdrRow.'" s="6"/>';
            $merges[] = "A{$lHdrRow}:B{$lHdrRow}";

            $lHdrCells .= $this->stringCell("C{$lHdrRow}", 'OK (Healthy)', 16);
            $lHdrCells .= '<c r="D'.$lHdrRow.'" s="16"/>';
            $merges[] = "C{$lHdrRow}:D{$lHdrRow}";

            $lHdrCells .= $this->stringCell("E{$lHdrRow}", 'NOT OK (Warning)', 17);
            $lHdrCells .= '<c r="F'.$lHdrRow.'" s="17"/>';
            $merges[] = "E{$lHdrRow}:F{$lHdrRow}";

            $lHdrCells .= $this->stringCell("G{$lHdrRow}", 'CRITICAL (Alert)', 18);
            $lHdrCells .= '<c r="H'.$lHdrRow.'" s="18"/>';
            $merges[] = "G{$lHdrRow}:H{$lHdrRow}";

            $lHdrCells .= $this->stringCell("I{$lHdrRow}", 'Meaning & Operational Guidance', 6);
            $lHdrCells .= '<c r="J'.$lHdrRow.'" s="6"/>';
            $lHdrCells .= '<c r="K'.$lHdrRow.'" s="6"/>';
            $merges[] = "I{$lHdrRow}:K{$lHdrRow}";

            $out[] = '<row r="'.$lHdrRow.'" ht="24" customHeight="1">'.$lHdrCells.'</row>';
            $sRow++;

            // Legend Items with threshold definitions
            $legendItems = [
                [
                    'metric' => 'Temperature (°C)',
                    'ok' => '<= 45°C',
                    'warn' => '46°C – 69°C',
                    'crit' => '>= 70°C',
                    'desc' => 'Composite operating temperature. >=70°C causes thermal throttling and accelerates NAND wear. Check cooling fans & airflow.',
                ],
                [
                    'metric' => 'Percentage Used (Wear %)',
                    'ok' => '<= 50%',
                    'warn' => '51% – 80%',
                    'crit' => '> 80%',
                    'desc' => 'Vendor endurance consumed. Plan spare SSD when wear is 51-80%; replace immediately when >80% before available spare blocks deplete.',
                ],
                [
                    'metric' => 'Critical Warning (Hex)',
                    'ok' => '0x00',
                    'warn' => 'N/A (Any flag is critical)',
                    'crit' => '> 0x00 (Any bit)',
                    'desc' => 'Hardware alert bitmask: 0x01 spare below threshold, 0x02 temp trip, 0x04 degraded reliability, 0x08 read-only. Immediate replacement required.',
                ],
                [
                    'metric' => 'Media & Data Errors',
                    'ok' => '0',
                    'warn' => 'N/A (Any error is critical)',
                    'crit' => '> 0 (Retire Drive)',
                    'desc' => 'Unrecoverable NAND ECC or read-write data integrity errors detected. Backup data and retire/replace drive immediately to prevent data loss.',
                ],
                [
                    'metric' => 'Error Log Entries',
                    'ok' => '0',
                    'warn' => '1 – 99',
                    'crit' => '>= 100',
                    'desc' => 'Cumulative error log entries (PCIe link retries, controller timeouts, protocol aborts). High counts indicate bus or controller degradation.',
                ],
                [
                    'metric' => 'Unsafe Shutdowns',
                    'ok' => '< 20',
                    'warn' => '20 – 49',
                    'crit' => '>= 50',
                    'desc' => 'Sudden power-loss events without flush notice. High counts risk Flash Translation Layer (FTL) corruption on SSDs without PLP.',
                ],
                [
                    'metric' => 'Warn Temp Time (min)',
                    'ok' => '0 min',
                    'warn' => '> 0 min',
                    'crit' => '> 60 min / High',
                    'desc' => 'Cumulative minutes spent at or above warning temperature threshold (typically 70°C). Indicates chronic thermal stress.',
                ],
                [
                    'metric' => 'Crit Temp Time (min)',
                    'ok' => '0 min',
                    'warn' => 'N/A (Any critical time is severe)',
                    'crit' => '> 0 min',
                    'desc' => 'Cumulative minutes spent at or above critical temperature threshold (typically 80-85°C). Critical thermal throttling occurred.',
                ],
                [
                    'metric' => 'Overall Drive Health Status',
                    'ok' => 'OK (All vitals normal)',
                    'warn' => 'NOT OK (Any warning range)',
                    'crit' => 'CRITICAL (Any alert range)',
                    'desc' => 'Overall drive condition based on highest detected severity. Drives marked CRITICAL require urgent maintenance or hardware replacement.',
                ],
            ];

            foreach ($legendItems as $item) {
                $cells = $this->stringCell("A{$sRow}", $item['metric'], 25);
                $cells .= '<c r="B'.$sRow.'" s="25"/>';
                $merges[] = "A{$sRow}:B{$sRow}";

                $cells .= $this->stringCell("C{$sRow}", $item['ok'], 16);
                $cells .= '<c r="D'.$sRow.'" s="16"/>';
                $merges[] = "C{$sRow}:D{$sRow}";

                $cells .= $this->stringCell("E{$sRow}", $item['warn'], 17);
                $cells .= '<c r="F'.$sRow.'" s="17"/>';
                $merges[] = "E{$sRow}:F{$sRow}";

                $cells .= $this->stringCell("G{$sRow}", $item['crit'], 18);
                $cells .= '<c r="H'.$sRow.'" s="18"/>';
                $merges[] = "G{$sRow}:H{$sRow}";

                $cells .= $this->stringCell("I{$sRow}", $item['desc'], 26);
                $cells .= '<c r="J'.$sRow.'" s="26"/>';
                $cells .= '<c r="K'.$sRow.'" s="26"/>';
                $merges[] = "I{$sRow}:K{$sRow}";

                $out[] = '<row r="'.$sRow.'" ht="26" customHeight="1">'.$cells.'</row>';
                $sRow++;
            }
        }

        $out[] = '</sheetData>';

        $out[] = '<mergeCells count="'.count($merges).'">';
        foreach ($merges as $ref) {
            $out[] = '<mergeCell ref="'.$ref.'"/>';
        }
        $out[] = '</mergeCells>';

        if ($hasChartData) {
            $out[] = '<drawing r:id="rId1"/>';
        }

        $out[] = '</worksheet>';

        return implode("\n", $out);
    }

    /**
     * Sheet 2 ("1-Month Data"):
     * Raw 30-day chronological telemetry table referenced by the embedded Excel charts.
     * Note: Missing numeric metrics are left as genuinely empty cells so Excel charts do not plot fake 0s.
     */
    private function buildDataSheetXml(array $nodeMeta, array $rows, array $effectiveDrives, Carbon $periodStart, Carbon $periodEnd): string
    {
        $totalCols = 2 + (count($effectiveDrives) * 2);
        $lastCol = $this->colLetter($totalCols);

        $plant = (string) ($nodeMeta['plant'] ?? '-');
        $line = (string) ($nodeMeta['line'] ?? '-');
        $side = (string) ($nodeMeta['side'] ?? '-');

        $out = [];
        $out[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $out[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Freeze top 4 rows
        $out[] = '<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

        $out[] = '<cols>';
        $out[] = '<col min="1" max="1" width="22" customWidth="1"/>';
        for ($c = 2; $c <= $totalCols; $c++) {
            $out[] = '<col min="'.$c.'" max="'.$c.'" width="18" customWidth="1"/>';
        }
        $out[] = '</cols>';

        $out[] = '<sheetData>';
        $merges = [];

        $title = "1-Month Telemetry Data - Plant {$plant} | Line {$line} | Side {$side}";
        $out[] = $this->buildMergedBannerRow(1, $title, $totalCols, 1, 26);
        $merges[] = "A1:{$lastCol}1";

        $subtitle = sprintf(
            'Period: %s to %s (%d Records)',
            $periodStart->format('Y-m-d H:i'),
            $periodEnd->format('Y-m-d H:i'),
            count($rows)
        );
        $out[] = $this->buildMergedBannerRow(2, $subtitle, $totalCols, 2, 18);
        $merges[] = "A2:{$lastCol}2";

        $out[] = '<row r="3" ht="8" customHeight="1"/>';

        // Row 4: Table Headers (referenced by Chart series titles)
        $headers = ['Timestamp', 'CPU Temp (°C)'];
        foreach ($effectiveDrives as $drive) {
            if ($drive === null) {
                $headers[] = 'SSD Temp (°C)';
                $headers[] = 'SSD Wear (%)';
            } else {
                $headers[] = "SSD {$drive} Temp (°C)";
                $headers[] = "SSD {$drive} Wear (%)";
            }
        }

        $headerCells = '';
        foreach ($headers as $idx => $headerText) {
            $headerCells .= $this->stringCell($this->colLetter($idx + 1).'4', $headerText, 6);
        }
        $out[] = '<row r="4" ht="24" customHeight="1">'.$headerCells.'</row>';

        // Rows 5+: Data
        $rowIdx = 5;
        if (empty($rows)) {
            $cells = $this->stringCell("A{$rowIdx}", 'No telemetry records found in the last 30 days.', 15);
            for ($c = 2; $c <= $totalCols; $c++) {
                $cells .= '<c r="'.$this->colLetter($c).$rowIdx.'" s="15"/>';
            }
            $out[] = '<row r="'.$rowIdx.'" ht="22" customHeight="1">'.$cells.'</row>';
            $merges[] = "A{$rowIdx}:{$lastCol}{$rowIdx}";
            $lastDataRowIdx = $rowIdx;
        } else {
            foreach ($rows as $i => $row) {
                $isOdd = ($i % 2) === 1;
                $textStyle = $isOdd ? 8 : 7;
                $numStyle = $isOdd ? 10 : 9;
                $decStyle = $isOdd ? 12 : 11;
                $pctStyle = $isOdd ? 14 : 13;

                $cells = $this->stringCell("A{$rowIdx}", (string) $row['timestamp'], $textStyle);

                $cpuVal = $row['cpu_temp'];
                if ($cpuVal !== null) {
                    $style = $this->isWholeNumber($cpuVal) ? $numStyle : $decStyle;
                    $cells .= $this->numberCell("B{$rowIdx}", $cpuVal, $style);
                } else {
                    $cells .= '<c r="B'.$rowIdx.'" s="'.$textStyle.'"/>';
                }

                $colIdx = 3;
                foreach ($effectiveDrives as $drive) {
                    $ssd = ($drive !== null && isset($row['ssds'][$drive])) ? $row['ssds'][$drive] : null;

                    $tempRef = $this->colLetter($colIdx).$rowIdx;
                    $tempVal = $ssd['temp'] ?? null;
                    if ($tempVal !== null) {
                        $style = $this->isWholeNumber($tempVal) ? $numStyle : $decStyle;
                        $cells .= $this->numberCell($tempRef, $tempVal, $style);
                    } else {
                        $cells .= '<c r="'.$tempRef.'" s="'.$textStyle.'"/>';
                    }
                    $colIdx++;

                    $wearRef = $this->colLetter($colIdx).$rowIdx;
                    $wearVal = $ssd['wear'] ?? null;
                    if ($wearVal !== null) {
                        $cells .= $this->numberCell($wearRef, $wearVal, $pctStyle);
                    } else {
                        $cells .= '<c r="'.$wearRef.'" s="'.$textStyle.'"/>';
                    }
                    $colIdx++;
                }

                $out[] = '<row r="'.$rowIdx.'">'.$cells.'</row>';
                $rowIdx++;
            }
            $lastDataRowIdx = $rowIdx - 1;
        }

        $out[] = '</sheetData>';

        if (! empty($rows)) {
            $out[] = '<autoFilter ref="A4:'.$lastCol.$lastDataRowIdx.'"/>';
        }

        $out[] = '<mergeCells count="'.count($merges).'">';
        foreach ($merges as $ref) {
            $out[] = '<mergeCell ref="'.$ref.'"/>';
        }
        $out[] = '</mergeCells>';

        $out[] = '</worksheet>';

        return implode("\n", $out);
    }

    /**
     * Sheet 3 ("Daily Summary"):
     * 30-day daily aggregated statistics (Samples, CPU Temp Avg/Max, per-drive SSD Temp Avg/Max & Wear Max).
     */
    private function buildDailySummarySheetXml(array $nodeMeta, array $rows, array $effectiveDrives, Carbon $periodStart, Carbon $periodEnd): string
    {
        $totalCols = 4 + (count($effectiveDrives) * 3);
        $lastCol = $this->colLetter($totalCols);

        $plant = (string) ($nodeMeta['plant'] ?? '-');
        $line = (string) ($nodeMeta['line'] ?? '-');
        $side = (string) ($nodeMeta['side'] ?? '-');

        $daily = [];
        foreach ($rows as $row) {
            $date = substr((string) $row['timestamp'], 0, 10);
            if (! isset($daily[$date])) {
                $daily[$date] = [
                    'samples' => 0,
                    'cpu_sum' => 0.0,
                    'cpu_cnt' => 0,
                    'cpu_max' => null,
                    'ssds' => [],
                ];
            }

            $daily[$date]['samples']++;

            if ($row['cpu_temp'] !== null) {
                $cpu = (float) $row['cpu_temp'];
                $daily[$date]['cpu_sum'] += $cpu;
                $daily[$date]['cpu_cnt']++;
                $daily[$date]['cpu_max'] = $daily[$date]['cpu_max'] === null ? $cpu : max($daily[$date]['cpu_max'], $cpu);
            }

            foreach ($effectiveDrives as $drive) {
                if ($drive === null || ! isset($row['ssds'][$drive])) {
                    continue;
                }
                if (! isset($daily[$date]['ssds'][$drive])) {
                    $daily[$date]['ssds'][$drive] = [
                        'temp_sum' => 0.0,
                        'temp_cnt' => 0,
                        'temp_max' => null,
                        'wear_max' => null,
                    ];
                }
                $ssd = $row['ssds'][$drive];
                if ($ssd['temp'] !== null) {
                    $t = (float) $ssd['temp'];
                    $daily[$date]['ssds'][$drive]['temp_sum'] += $t;
                    $daily[$date]['ssds'][$drive]['temp_cnt']++;
                    $daily[$date]['ssds'][$drive]['temp_max'] = $daily[$date]['ssds'][$drive]['temp_max'] === null
                        ? $t
                        : max($daily[$date]['ssds'][$drive]['temp_max'], $t);
                }
                if ($ssd['wear'] !== null) {
                    $w = (float) $ssd['wear'];
                    $daily[$date]['ssds'][$drive]['wear_max'] = $daily[$date]['ssds'][$drive]['wear_max'] === null
                        ? $w
                        : max($daily[$date]['ssds'][$drive]['wear_max'], $w);
                }
            }
        }

        $out = [];
        $out[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $out[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        $out[] = '<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

        $out[] = '<cols>';
        $out[] = '<col min="1" max="1" width="14" customWidth="1"/>';
        $out[] = '<col min="2" max="2" width="12" customWidth="1"/>';
        for ($c = 3; $c <= $totalCols; $c++) {
            $out[] = '<col min="'.$c.'" max="'.$c.'" width="18" customWidth="1"/>';
        }
        $out[] = '</cols>';

        $out[] = '<sheetData>';
        $merges = [];

        $title = "Daily Telemetry Summary - Plant {$plant} | Line {$line} | Side {$side}";
        $out[] = $this->buildMergedBannerRow(1, $title, $totalCols, 1, 26);
        $merges[] = "A1:{$lastCol}1";

        $subtitle = sprintf(
            'Period: %s to %s (Last 30 Days)',
            $periodStart->format('Y-m-d'),
            $periodEnd->format('Y-m-d')
        );
        $out[] = $this->buildMergedBannerRow(2, $subtitle, $totalCols, 2, 18);
        $merges[] = "A2:{$lastCol}2";

        $out[] = '<row r="3" ht="8" customHeight="1"/>';

        $headers = ['Date', 'Samples', 'CPU Temp Avg (°C)', 'CPU Temp Max (°C)'];
        foreach ($effectiveDrives as $drive) {
            $prefix = $drive === null ? 'SSD' : "SSD {$drive}";
            $headers[] = "{$prefix} Temp Avg (°C)";
            $headers[] = "{$prefix} Temp Max (°C)";
            $headers[] = "{$prefix} Wear Max (%)";
        }

        $headerCells = '';
        foreach ($headers as $idx => $headerText) {
            $headerCells .= $this->stringCell($this->colLetter($idx + 1).'4', $headerText, 6);
        }
        $out[] = '<row r="4" ht="24" customHeight="1">'.$headerCells.'</row>';

        $rowIdx = 5;
        if (empty($daily)) {
            $cells = $this->stringCell("A{$rowIdx}", 'No daily summary data available for the last 30 days.', 15);
            for ($c = 2; $c <= $totalCols; $c++) {
                $cells .= '<c r="'.$this->colLetter($c).$rowIdx.'" s="15"/>';
            }
            $out[] = '<row r="'.$rowIdx.'" ht="22" customHeight="1">'.$cells.'</row>';
            $merges[] = "A{$rowIdx}:{$lastCol}{$rowIdx}";
            $lastDataRowIdx = $rowIdx;
        } else {
            $i = 0;
            foreach ($daily as $date => $stats) {
                $isOdd = ($i % 2) === 1;
                $textStyle = $isOdd ? 8 : 7;
                $intStyle = $isOdd ? 10 : 9;
                $decStyle = $isOdd ? 12 : 11;
                $pctStyle = $isOdd ? 14 : 13;

                $cells = $this->stringCell("A{$rowIdx}", $date, $textStyle);
                $cells .= $this->numberCell("B{$rowIdx}", $stats['samples'], $intStyle);

                if ($stats['cpu_cnt'] > 0) {
                    $cells .= $this->numberCell("C{$rowIdx}", round($stats['cpu_sum'] / $stats['cpu_cnt'], 1), $decStyle);
                    $cells .= $this->numberCell("D{$rowIdx}", round($stats['cpu_max'], 1), $this->isWholeNumber($stats['cpu_max']) ? $intStyle : $decStyle);
                } else {
                    $cells .= $this->stringCell("C{$rowIdx}", '-', $textStyle);
                    $cells .= $this->stringCell("D{$rowIdx}", '-', $textStyle);
                }

                $colIdx = 5;
                foreach ($effectiveDrives as $drive) {
                    $ssdStat = ($drive !== null && isset($stats['ssds'][$drive])) ? $stats['ssds'][$drive] : null;
                    if ($ssdStat && $ssdStat['temp_cnt'] > 0) {
                        $cells .= $this->numberCell($this->colLetter($colIdx).$rowIdx, round($ssdStat['temp_sum'] / $ssdStat['temp_cnt'], 1), $decStyle);
                        $colIdx++;
                        $cells .= $this->numberCell($this->colLetter($colIdx).$rowIdx, round($ssdStat['temp_max'], 1), $this->isWholeNumber($ssdStat['temp_max']) ? $intStyle : $decStyle);
                        $colIdx++;
                    } else {
                        $cells .= $this->stringCell($this->colLetter($colIdx).$rowIdx, '-', $textStyle);
                        $colIdx++;
                        $cells .= $this->stringCell($this->colLetter($colIdx).$rowIdx, '-', $textStyle);
                        $colIdx++;
                    }

                    if ($ssdStat && $ssdStat['wear_max'] !== null) {
                        $cells .= $this->numberCell($this->colLetter($colIdx).$rowIdx, $ssdStat['wear_max'], $pctStyle);
                    } else {
                        $cells .= $this->stringCell($this->colLetter($colIdx).$rowIdx, '-', $textStyle);
                    }
                    $colIdx++;
                }

                $out[] = '<row r="'.$rowIdx.'">'.$cells.'</row>';
                $rowIdx++;
                $i++;
            }
            $lastDataRowIdx = $rowIdx - 1;
        }

        $out[] = '</sheetData>';

        if (! empty($daily)) {
            $out[] = '<autoFilter ref="A4:'.$lastCol.$lastDataRowIdx.'"/>';
        }

        $out[] = '<mergeCells count="'.count($merges).'">';
        foreach ($merges as $ref) {
            $out[] = '<mergeCell ref="'.$ref.'"/>';
        }
        $out[] = '</mergeCells>';

        $out[] = '</worksheet>';

        return implode("\n", $out);
    }

    /**
     * Build a native ECMA-376 DrawingML Line Chart XML with cached series/axis values
     * so it renders cleanly in Excel Desktop, Excel Web, LibreOffice Calc, and Google Sheets.
     *
     * @param  array<int, string>  $categories
     * @param  array<int, array{name: string, nameRef: string, valRef: string, values: array<int, ?float>, color: string}>  $seriesList
     */
    private function buildLineChartXml(
        string $title,
        string $yAxisTitle,
        string $yAxisFormat,
        string $catRef,
        array $categories,
        array $seriesList,
        int $axIdBase,
        bool $yMinZero
    ): string {
        $catAxId = $axIdBase + 1;
        $valAxId = $axIdBase + 2;
        $catCount = count($categories);

        // Build shared category cache string once for this chart
        $catCacheParts = ['<c:strCache><c:ptCount val="'.$catCount.'"/>'];
        foreach ($categories as $idx => $ts) {
            $catCacheParts[] = '<c:pt idx="'.$idx.'"><c:v>'.$this->xmlEscape($ts).'</c:v></c:pt>';
        }
        $catCacheParts[] = '</c:strCache>';
        $catCacheXml = implode('', $catCacheParts);

        $seriesXmlParts = [];
        foreach ($seriesList as $sIdx => $series) {
            $nameEsc = $this->xmlEscape($series['name']);
            $nameRefEsc = $this->xmlEscape($series['nameRef']);
            $valRefEsc = $this->xmlEscape($series['valRef']);
            $catRefEsc = $this->xmlEscape($catRef);
            $color = $series['color'];

            $numCacheParts = ['<c:numCache><c:formatCode>'.$this->xmlEscape($yAxisFormat).'</c:formatCode><c:ptCount val="'.$catCount.'"/>'];
            foreach ($series['values'] as $pIdx => $val) {
                if ($val !== null) {
                    $numCacheParts[] = '<c:pt idx="'.$pIdx.'"><c:v>'.$val.'</c:v></c:pt>';
                }
            }
            $numCacheParts[] = '</c:numCache>';
            $numCacheXml = implode('', $numCacheParts);

            $seriesXmlParts[] = <<<XML
<c:ser>
    <c:idx val="{$sIdx}"/>
    <c:order val="{$sIdx}"/>
    <c:tx>
        <c:strRef>
            <c:f>{$nameRefEsc}</c:f>
            <c:strCache><c:ptCount val="1"/><c:pt idx="0"><c:v>{$nameEsc}</c:v></c:pt></c:strCache>
        </c:strRef>
    </c:tx>
    <c:spPr>
        <a:ln w="19050" cap="rnd">
            <a:solidFill><a:srgbClr val="{$color}"/></a:solidFill>
            <a:round/>
        </a:ln>
    </c:spPr>
    <c:marker><c:symbol val="none"/></c:marker>
    <c:cat>
        <c:strRef>
            <c:f>{$catRefEsc}</c:f>
            {$catCacheXml}
        </c:strRef>
    </c:cat>
    <c:val>
        <c:numRef>
            <c:f>{$valRefEsc}</c:f>
            {$numCacheXml}
        </c:numRef>
    </c:val>
    <c:smooth val="0"/>
</c:ser>
XML;
        }

        $allSeriesXml = implode("\n", $seriesXmlParts);
        $titleXml = $this->buildChartTitleXml($title, 1100, true, '0F172A');
        $yTitleXml = $this->buildChartTitleXml($yAxisTitle, 900, true, '475569');
        $yAxisFormatEsc = $this->xmlEscape($yAxisFormat);
        $yScalingXml = $yMinZero
            ? '<c:scaling><c:orientation val="minMax"/><c:min val="0"/></c:scaling>'
            : '<c:scaling><c:orientation val="minMax"/></c:scaling>';

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"
              xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
              xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <c:date1904 val="0"/>
    <c:lang val="en-US"/>
    <c:roundedCorners val="0"/>
    <c:chart>
        {$titleXml}
        <c:autoTitleDeleted val="0"/>
        <c:plotArea>
            <c:layout/>
            <c:lineChart>
                <c:grouping val="standard"/>
                <c:varyColors val="0"/>
                {$allSeriesXml}
                <c:dLbls>
                    <c:showLegendKey val="0"/>
                    <c:showVal val="0"/>
                    <c:showCatName val="0"/>
                    <c:showSerName val="0"/>
                    <c:showPercent val="0"/>
                    <c:showBubbleSize val="0"/>
                </c:dLbls>
                <c:marker val="0"/>
                <c:smooth val="0"/>
                <c:axId val="{$catAxId}"/>
                <c:axId val="{$valAxId}"/>
            </c:lineChart>
            <c:catAx>
                <c:axId val="{$catAxId}"/>
                <c:scaling><c:orientation val="minMax"/></c:scaling>
                <c:delete val="0"/>
                <c:axPos val="b"/>
                <c:majorTickMark val="out"/>
                <c:minorTickMark val="none"/>
                <c:tickLblPos val="nextTo"/>
                <c:spPr><a:ln w="9525"><a:solidFill><a:srgbClr val="CBD5E1"/></a:solidFill></a:ln></c:spPr>
                <c:txPr>
                    <a:bodyPr/>
                    <a:lstStyle/>
                    <a:p><a:pPr><a:defRPr sz="800"><a:solidFill><a:srgbClr val="64748B"/></a:solidFill></a:defRPr></a:pPr><a:endParaRPr lang="en-US"/></a:p>
                </c:txPr>
                <c:crossAx val="{$valAxId}"/>
                <c:crosses val="autoZero"/>
                <c:auto val="1"/>
                <c:lblAlgn val="ctr"/>
                <c:lblOffset val="100"/>
                <c:noMultiLvlLbl val="0"/>
            </c:catAx>
            <c:valAx>
                <c:axId val="{$valAxId}"/>
                {$yScalingXml}
                <c:delete val="0"/>
                <c:axPos val="l"/>
                <c:majorGridlines>
                    <c:spPr><a:ln w="9525"><a:solidFill><a:srgbClr val="E2E8F0"/></a:solidFill></a:ln></c:spPr>
                </c:majorGridlines>
                {$yTitleXml}
                <c:numFmt formatCode="{$yAxisFormatEsc}" sourceLinked="0"/>
                <c:majorTickMark val="none"/>
                <c:minorTickMark val="none"/>
                <c:tickLblPos val="nextTo"/>
                <c:spPr><a:ln w="9525"><a:noFill/></a:ln></c:spPr>
                <c:txPr>
                    <a:bodyPr/>
                    <a:lstStyle/>
                    <a:p><a:pPr><a:defRPr sz="850"><a:solidFill><a:srgbClr val="64748B"/></a:solidFill></a:defRPr></a:pPr><a:endParaRPr lang="en-US"/></a:p>
                </c:txPr>
                <c:crossAx val="{$catAxId}"/>
                <c:crosses val="autoZero"/>
                <c:crossBetween val="between"/>
            </c:valAx>
            <c:spPr>
                <a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill>
                <a:ln><a:noFill/></a:ln>
            </c:spPr>
        </c:plotArea>
        <c:legend>
            <c:legendPos val="t"/>
            <c:overlay val="0"/>
            <c:txPr>
                <a:bodyPr/>
                <a:lstStyle/>
                <a:p><a:pPr><a:defRPr sz="850" b="1"><a:solidFill><a:srgbClr val="334155"/></a:solidFill></a:defRPr></a:pPr><a:endParaRPr lang="en-US"/></a:p>
            </c:txPr>
        </c:legend>
        <c:plotVisOnly val="1"/>
        <c:dispBlanksAs val="span"/>
    </c:chart>
    <c:spPr>
        <a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill>
        <a:ln w="12700"><a:solidFill><a:srgbClr val="CBD5E1"/></a:solidFill></a:ln>
    </c:spPr>
</c:chartSpace>
XML;
    }

    private function buildChartTitleXml(string $text, int $size, bool $bold, string $hexColor): string
    {
        $esc = $this->xmlEscape($text);
        $bAttr = $bold ? ' b="1"' : ' b="0"';

        return <<<XML
<c:title>
    <c:tx>
        <c:rich>
            <a:bodyPr/>
            <a:lstStyle/>
            <a:p>
                <a:pPr><a:defRPr sz="{$size}"{$bAttr}><a:solidFill><a:srgbClr val="{$hexColor}"/></a:solidFill></a:defRPr></a:pPr>
                <a:r>
                    <a:rPr lang="en-US" sz="{$size}"{$bAttr}><a:solidFill><a:srgbClr val="{$hexColor}"/></a:solidFill></a:rPr>
                    <a:t>{$esc}</a:t>
                </a:r>
            </a:p>
        </c:rich>
    </c:tx>
    <c:layout/>
    <c:overlay val="0"/>
</c:title>
XML;
    }

    private function buildDrawingXml(): string
    {
        $anchors = [
            // Chart 1 (CPU Temp): Col H (7) to right edge of P (16), Row 4 (3) to Row 18 (18)
            $this->buildChartAnchorXml(2, 'CPU Temperature Chart', 'rId1', 7, 3, 16, 18),
            // Chart 2 (SSD Temp): Col A (0) to right edge of H (8), Row 20 (19) to Row 36 (36)
            $this->buildChartAnchorXml(3, 'SSD Temperature Chart', 'rId2', 0, 19, 8, 36),
            // Chart 3 (SSD Wear): Col I (8) to right edge of P (16), Row 20 (19) to Row 36 (36)
            $this->buildChartAnchorXml(4, 'SSD Wear Chart', 'rId3', 8, 19, 16, 36),
        ];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'."\n"
            .implode("\n", $anchors)."\n"
            .'</xdr:wsDr>';
    }

    private function buildChartAnchorXml(int $id, string $name, string $relId, int $fromCol, int $fromRow, int $toCol, int $toRow): string
    {
        $nameEsc = $this->xmlEscape($name);

        return <<<XML
<xdr:twoCellAnchor>
    <xdr:from>
        <xdr:col>{$fromCol}</xdr:col>
        <xdr:colOff>45720</xdr:colOff>
        <xdr:row>{$fromRow}</xdr:row>
        <xdr:rowOff>0</xdr:rowOff>
    </xdr:from>
    <xdr:to>
        <xdr:col>{$toCol}</xdr:col>
        <xdr:colOff>0</xdr:colOff>
        <xdr:row>{$toRow}</xdr:row>
        <xdr:rowOff>0</xdr:rowOff>
    </xdr:to>
    <xdr:graphicFrame macro="">
        <xdr:nvGraphicFramePr>
            <xdr:cNvPr id="{$id}" name="{$nameEsc}"/>
            <xdr:cNvGraphicFramePr/>
        </xdr:nvGraphicFramePr>
        <xdr:xfrm>
            <a:off x="0" y="0"/>
            <a:ext cx="0" cy="0"/>
        </xdr:xfrm>
        <a:graphic>
            <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">
                <c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" r:id="{$relId}"/>
            </a:graphicData>
        </a:graphic>
    </xdr:graphicFrame>
    <xdr:clientData/>
</xdr:twoCellAnchor>
XML;
    }

    private function buildSheet1RelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>
</Relationships>
XML;
    }

    private function buildDrawingRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart2.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart3.xml"/>
</Relationships>
XML;
    }

    private function buildMergedBannerRow(int $rowIdx, string $text, int $colCount, int $styleIdx, int $height): string
    {
        $cells = $this->stringCell("A{$rowIdx}", $text, $styleIdx);
        for ($c = 2; $c <= $colCount; $c++) {
            $cells .= '<c r="'.$this->colLetter($c).$rowIdx.'" s="'.$styleIdx.'"/>';
        }

        return '<row r="'.$rowIdx.'" ht="'.$height.'" customHeight="1">'.$cells.'</row>';
    }

    private function stringCell(string $ref, string $value, int $styleIdx): string
    {
        return '<c r="'.$ref.'" s="'.$styleIdx.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xmlEscape($value).'</t></is></c>';
    }

    private function numberCell(string $ref, float|int $value, int $styleIdx): string
    {
        return '<c r="'.$ref.'" s="'.$styleIdx.'"><v>'.$value.'</v></c>';
    }

    private function xmlEscape(string $value): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return htmlspecialchars($clean, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function isWholeNumber(float|int $value): bool
    {
        return abs($value - round($value)) < 0.00001;
    }

    private function colLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    private function buildContentTypesXml(bool $hasChartData): string
    {
        $chartOverrides = $hasChartData ? <<<'XML'

    <Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>
    <Override PartName="/xl/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>
    <Override PartName="/xl/charts/chart2.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>
    <Override PartName="/xl/charts/chart3.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>
XML : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>{$chartOverrides}
    <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
    <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;
    }

    private function buildRootRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
    }

    private function buildAppPropsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">
    <Application>NGC Dashboard</Application>
</Properties>
XML;
    }

    private function buildCorePropsXml(array $nodeMeta, string $isoTimestamp): string
    {
        $title = $this->xmlEscape(
            sprintf('NGC Node Report - Plant %s Line %s Side %s', $nodeMeta['plant'] ?? '-', $nodeMeta['line'] ?? '-', $nodeMeta['side'] ?? '-')
        );

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
                   xmlns:dc="http://purl.org/dc/elements/1.1/"
                   xmlns:dcterms="http://purl.org/dc/terms/"
                   xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dc:title>{$title}</dc:title>
    <dc:creator>NGC Dashboard</dc:creator>
    <dcterms:created xsi:type="dcterms:W3CDTF">{$isoTimestamp}</dcterms:created>
    <dcterms:modified xsi:type="dcterms:W3CDTF">{$isoTimestamp}</dcterms:modified>
</cp:coreProperties>
XML;
    }

    private function buildWorkbookXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Node Report" sheetId="1" r:id="rId1"/>
        <sheet name="1-Month Data" sheetId="2" r:id="rId2"/>
        <sheet name="Daily Summary" sheetId="3" r:id="rId3"/>
    </sheets>
</workbook>
XML;
    }

    private function buildWorkbookRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>
    <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function buildStylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <numFmts count="2">
        <numFmt numFmtId="164" formatCode="0.0"/>
        <numFmt numFmtId="165" formatCode="0&quot;%&quot;"/>
    </numFmts>
    <fonts count="11">
        <font><sz val="10"/><color rgb="FF1E293B"/><name val="Calibri"/></font>
        <font><b/><sz val="13"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
        <font><i/><sz val="9.5"/><color rgb="FF475569"/><name val="Calibri"/></font>
        <font><b/><sz val="10.5"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
        <font><b/><sz val="10"/><color rgb="FF1E293B"/><name val="Calibri"/></font>
        <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
        <font><b/><sz val="9.5"/><color rgb="FF166534"/><name val="Calibri"/></font>
        <font><b/><sz val="9.5"/><color rgb="FF92400E"/><name val="Calibri"/></font>
        <font><b/><sz val="9.5"/><color rgb="FF991B1B"/><name val="Calibri"/></font>
        <font><sz val="9"/><color rgb="FF334155"/><name val="Calibri"/></font>
        <font><b/><sz val="9.5"/><color rgb="FF1E293B"/><name val="Calibri"/></font>
    </fonts>
    <fills count="11">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFEEF2FF"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF4F46E5"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>
    </fills>
    <borders count="2">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border>
            <left style="thin"><color rgb="FFCBD5E1"/></left>
            <right style="thin"><color rgb="FFCBD5E1"/></right>
            <top style="thin"><color rgb="FFCBD5E1"/></top>
            <bottom style="thin"><color rgb="FFCBD5E1"/></bottom>
            <diagonal/>
        </border>
    </borders>
    <cellStyleXfs count="1">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
    </cellStyleXfs>
    <cellXfs count="28">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
        <xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
        <xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
        <xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
        <xf numFmtId="0" fontId="5" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="1" fontId="0" fillId="5" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="164" fontId="0" fillId="5" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="165" fontId="0" fillId="5" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="0" fontId="2" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="6" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="7" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="8" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="1" fontId="6" fillId="7" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="1" fontId="7" fillId="8" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="1" fontId="8" fillId="9" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="165" fontId="6" fillId="7" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="165" fontId="7" fillId="8" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="165" fontId="8" fillId="9" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="0" fontId="10" fillId="10" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
        <xf numFmtId="0" fontId="9" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="5" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>
    </cellXfs>
</styleSheet>
XML;
    }
}
