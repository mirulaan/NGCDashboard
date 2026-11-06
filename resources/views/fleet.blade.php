<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plant {{ $plant }} View</title>
    
    <!-- Local Offline Assets -->
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('bitmap_logo.ico') }}">
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] },
                    boxShadow: { 'soft': '0 4px 20px -2px rgba(0,0,0,0.05)' }
                }
            }
        }
    </script>
</head>
<body class="bg-[#F8FAFC] text-slate-800 font-sans antialiased min-h-screen p-4 md:p-8">

    <div class="max-w-[1600px] mx-auto">
        <!-- Header -->
        <header class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
            <div>
                <div class="flex items-center gap-3 mb-1">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-cyan-400 flex items-center justify-center text-white font-bold text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                    </div>
                    <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Plant {{ $plant }} Fleet Status</h1>
                </div>
                <p class="text-slate-500 text-sm font-medium ml-11">Monitoring {{ count(config('telemetry.lines', [])) }} Lines • Dual-Side Topologies</p>
            </div>
            
            <div class="flex flex-wrap items-center gap-3">
                
                <!-- Sync Backups Button -->
                <button id="btn-refresh-fleet-backups" onclick="fetchFleetBackups()" class="flex items-center gap-1.5 bg-white p-2 px-3 rounded-xl shadow-sm border border-slate-200 text-xs font-bold text-slate-700 hover:text-indigo-600 transition-colors" title="Check SFTP Backup Status">
                    <svg id="icon-refresh-fleet-backups" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    Sync Backups
                </button>

                <!-- Status Legend -->
                <div class="flex items-center gap-4 text-xs font-semibold bg-white p-2 rounded-xl shadow-sm border border-slate-200">
                    <span class="flex items-center gap-1.5 px-2"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Online</span>
                    <span class="flex items-center gap-1.5 px-2"><span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span> Offline</span>
                </div>

                <!-- Auth UI -->
                @auth
                    <div class="flex items-center gap-3 bg-white p-2 px-3 rounded-xl shadow-sm border border-slate-200">
                        <span class="text-xs font-extrabold text-slate-700 uppercase">{{ auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('logout') }}" class="m-0">
                            @csrf
                            <button type="submit" class="text-[10px] bg-rose-50 text-rose-600 hover:bg-rose-100 font-bold px-2 py-1 rounded transition-colors">Logout</button>
                        </form>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="flex items-center gap-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 p-2 px-3 rounded-xl shadow-sm border border-indigo-100 transition-colors text-xs font-bold">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                        Login
                    </a>
                @endauth
            </div>
        </header>

        <!-- 24-Node Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4">
            @foreach($grid as $node)
                <a href="{{ route('node.show', ['plant' => $node['plant'], 'line' => $node['line'], 'side' => $node['side']]) }}" class="bg-white rounded-2xl p-4 shadow-soft border {{ $node['status'] ? 'border-emerald-100 hover:border-indigo-300' : 'border-rose-100 opacity-80' }} transition-colors relative overflow-hidden flex flex-col h-full">
                    
                    <!-- Top row: Line, Side, Status, and Backup Icon -->
                    <div class="flex justify-between items-center mb-3 pb-3 border-b border-slate-100">
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block leading-none mb-1">Line {{ $node['line'] }}</span>
                            <div class="flex items-center gap-1.5">
                                <span class="text-lg font-extrabold text-slate-800 leading-none">Side {{ $node['side'] }}</span>
                                <!-- Backup Indicator Icon (Hidden by Default) -->
                                <svg id="backup-indicator-{{ $node['line'] }}-{{ $node['side'] }}" class="backup-indicator hidden w-4 h-4 cursor-help transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path>
                                </svg>
                            </div>
                        </div>
                        
                        @if($node['status'])
                            <span class="relative flex h-3 w-3">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
                            </span>
                        @else
                            <span class="relative flex h-3 w-3">
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-rose-500"></span>
                            </span>
                        @endif
                    </div>
                    
                    <!-- PC Thermal Status (CPU/GPU) -->
                    @if(isset($node['data']['PC Thermal']))
                        @php
                            // CPU Logic
                            $cpuTemp = (int)($node['data']['PC Thermal']['CPU Temp'] ?? 0);
                            $isCpuThrottling = strtolower($node['data']['PC Thermal']['CPU Thermal Throttle'] ?? 'no') === 'yes';
                            $cpuBorder = $isCpuThrottling ? 'border-rose-500 bg-rose-50 text-rose-700' : 'border-emerald-500 bg-emerald-50 text-emerald-700';
                            $cpuTempText = $cpuTemp >= 80 ? 'text-rose-600' : ($cpuTemp >= 70 ? 'text-orange-500' : 'text-slate-700');

                            // GPU Logic
                            $gpuTemp = (int)($node['data']['PC Thermal']['GPU Temp'] ?? 0);
                            $gpuClock = (float)($node['data']['PC Thermal']['GPU Clock'] ?? 0);
                            $isGpuThrottling = $gpuClock <= 1000;
                            $gpuBorder = $isGpuThrottling ? 'border-rose-500 bg-rose-50 text-rose-700' : 'border-emerald-500 bg-emerald-50 text-emerald-700';
                            $gpuTempText = $gpuTemp >= 80 ? 'text-rose-600' : ($gpuTemp >= 70 ? 'text-orange-500' : 'text-slate-700');
                        @endphp
                        
                        <div class="mb-3 grid grid-cols-2 gap-2">
                            <!-- CPU Box -->
                            <div class="flex flex-col items-center justify-center border-[1.5px] rounded-lg p-1.5 transition-colors {{ $cpuBorder }}">
                                <span class="font-extrabold text-[9px] uppercase tracking-widest opacity-80 mb-0.5">CPU</span>
                                <span class="font-mono font-bold text-sm {{ $cpuTempText }} leading-none">{{ $cpuTemp }}°C</span>
                            </div>
                            
                            <!-- GPU Box -->
                            <div class="flex flex-col items-center justify-center border-[1.5px] rounded-lg p-1.5 transition-colors {{ $gpuBorder }}">
                                <span class="font-extrabold text-[9px] uppercase tracking-widest opacity-80 mb-0.5">GPU</span>
                                <span class="font-mono font-bold text-sm {{ $gpuTempText }} leading-none">{{ $gpuTemp }}°C</span>
                            </div>
                        </div>
                    @endif

                    <!-- SSD List -->
                    <div class="flex-1 flex flex-col gap-2">
                        @if(isset($node['data']['SSD_Status']))
                            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Storage Health</div>
                            
                            @foreach($node['data']['SSD_Status'] as $ssd)
                                @php
                                    $wear = $ssd['PercentageUsed'];
                                    if ($wear > 80) {
                                        $dot = 'bg-rose-500';
                                        $pulse = 'bg-rose-400 animate-[ping_0.5s_cubic-bezier(0,0,0.2,1)_infinite]';
                                    } elseif ($wear > 50) {
                                        $dot = 'bg-orange-500';
                                        $pulse = 'bg-orange-400 animate-[ping_3s_cubic-bezier(0,0,0.2,1)_infinite]';
                                    } else {
                                        $dot = 'bg-emerald-500';
                                        $pulse = 'hidden';
                                    }

                                    $totalSpace = max($ssd['TotalSpaceGB'], 1); 
                                    $remainingPercentage = ($ssd['RemainingSpaceGB'] / $totalSpace) * 100;
                                    $isLowCapacity = $remainingPercentage <= 20;

                                    $ssdBoxClass = $isLowCapacity 
                                        ? 'bg-rose-50 border-rose-500 animate-[pulse_0.6s_ease-in-out_infinite]' 
                                        : 'bg-slate-50 border-slate-100';
                                        
                                    $driveLetterClass = $isLowCapacity ? 'text-rose-700' : 'text-slate-700';
                                    $freeSpaceClass = $isLowCapacity ? 'text-rose-600 font-bold' : 'text-slate-500';

                                    $temp = $ssd['TemperatureCelsius'] ?? 0;
                                    $tempClass = $temp > 70 ? 'text-rose-600 font-bold' : 'text-slate-500';
                                @endphp
                                
                                <div class="flex items-center justify-between rounded-lg p-2 border text-xs transition-colors {{ $ssdBoxClass }}">
                                    <div class="flex items-center gap-2">
                                        <span class="relative flex h-2 w-2 shrink-0">
                                            <span class="absolute inline-flex h-full w-full rounded-full opacity-75 {{ $pulse }}"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 {{ $dot }}"></span>
                                        </span>
                                        <span class="text-[10px] font-mono font-semibold text-slate-500">{{ $wear }}%</span>
                                        <span class="text-[10px] font-mono font-semibold {{ $tempClass }}">{{ $temp }}°C</span>
                                        <span class="font-bold {{ $driveLetterClass }}">{{ $ssd['DriveLetter'] }}</span>
                                    </div>
                                    <div class="font-medium {{ $freeSpaceClass }}">
                                        {{ number_format($ssd['RemainingSpaceGB'], 0) }} GB Free
                                    </div>
                                </div>
                            @endforeach
                        @else
                            <div class="flex-1 flex flex-col items-center justify-center text-rose-400 mt-2">
                                <svg class="w-6 h-6 mb-1 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <span class="text-xs font-semibold">NO TELEMETRY</span>
                            </div>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    </div>

    <script>
        const currentPlant = "{{ $plant }}";
        const backupCacheKey = `sftp_backups_plant_${currentPlant}`;

        // Load backups immediately on page load so the 10-second reload doesn't erase them
        window.addEventListener('DOMContentLoaded', () => {
            const cached = sessionStorage.getItem(backupCacheKey);
            if (cached) {
                applyBackupIcons(JSON.parse(cached));
            }
        });

        // Refreshes the entire page every 10 seconds
        setInterval(() => window.location.reload(), 10000);

        async function fetchFleetBackups() {
            const btn = document.getElementById('btn-refresh-fleet-backups');
            const icon = document.getElementById('icon-refresh-fleet-backups');
            
            icon.classList.add('animate-[spin_1s_linear_infinite]');
            btn.disabled = true;

            try {
                const response = await fetch(`/api/sftp/backups/${currentPlant}`);
                const result = await response.json();
                
                if (result.status === 'success') {
                    sessionStorage.setItem(backupCacheKey, JSON.stringify(result.data));
                    applyBackupIcons(result.data);
                }
            } catch (e) {
                console.error("Failed to fetch fleet backups", e);
            }

            icon.classList.remove('animate-[spin_1s_linear_infinite]');
            btn.disabled = false;
        }

        function applyBackupIcons(backups) {
            // Hide all icons first before applying new ones
            document.querySelectorAll('.backup-indicator').forEach(el => el.classList.add('hidden'));

            const now = new Date();

            backups.forEach(backup => {
                // Extracts line and side from format like "93L.zip"
                const match = backup.filename.match(/^(\d+)([A-Za-z])\.zip$/i);
                
                if (match) {
                    const line = match[1];
                    const side = match[2].toUpperCase();
                    const indicator = document.getElementById(`backup-indicator-${line}-${side}`);
                    
                    if (indicator) {
                        // Safe date parsing to avoid cross-browser NaN issues
                        const backupDateStr = backup.changed_date.replace(/-/g, '/');
                        const backupDate = new Date(backupDateStr);
                        
                        // Calculate difference in days
                        const diffDays = (now - backupDate) / (1000 * 60 * 60 * 24);
                        
                        indicator.classList.remove('hidden', 'text-emerald-500', 'text-rose-500');
                        
                        // If file is older than 24 hours, turn it Red. Otherwise, Green.
                        if (diffDays > 1) {
                            indicator.classList.add('text-rose-500');
                            indicator.title = `Outdated Backup: ${backup.changed_date}`;
                        } else {
                            indicator.classList.add('text-emerald-500');
                            indicator.title = `Recent Backup: ${backup.changed_date}`;
                        }
                    }
                }
            });
        }
    </script>
    
    <!-- Floating Settings Button -->
    @auth
        <a href="{{ route('settings') }}" class="fixed bottom-6 left-6 flex items-center gap-2 bg-white text-slate-500 px-4 py-2.5 rounded-full shadow-soft border border-slate-200 hover:text-indigo-600 hover:border-indigo-200 hover:shadow-md transition-all z-50 font-bold text-xs group">
            <svg class="w-4 h-4 group-hover:rotate-90 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            Settings
        </a>
    @endauth
</body>
</html>