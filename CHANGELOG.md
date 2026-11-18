# Changelog

All notable changes to **NGC Dashboard** are documented in this file.
Versioning follows [Semantic Versioning](https://semver.org/) (`MAJOR.MINOR.PATCH`).

## [1.0.0] - 2026-11-19
### Added
- Application version control (`VERSION` + `config('app.version')`) displayed as a badge on the Fleet Dashboard page and in deployment output.
- 1-Month Node Telemetry Excel (`.xlsx`) report export with embedded native charts (CPU Temp, SSD Temp, SSD Wear), raw data sheet, and daily summary sheet.
- Full NVMe SMART health telemetry collection and UI display (Critical Warning, Media Errors, Error Log Entries, Unsafe Shutdowns, Warning/Critical Temp Time).

### Fixed
- Eliminated PHP memory exhaustion (`Allowed memory size exhausted`) on high-volume 30-day nodes (e.g., Plant 8) by streaming lightweight chart queries via `cursor()`, selecting only required chart fields, and downsampling older (>24h) points while preserving full 24h resolution.
- Reduced peak memory usage during 1-month Excel report generation by streaming DB queries and releasing XML buffers as entries are written to the archive.
