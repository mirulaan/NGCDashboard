<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plant: {{ $plant }}-{{ $line }}-{{ $side }}</title>
    
    <!-- Local Offline Assets -->
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('bitmap_logo.ico') }}">
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
    
    <!-- Local Chart.js & Trendline Plugin -->
    <script src="{{ asset('js/chart.js') }}"></script>
    <script src="{{ asset('js/chartjs-plugin-trendline.js') }}"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] },
                    boxShadow: {
                        'soft': '0 4px 20px -2px rgba(0,0,0,0.05)',
                        'glow': '0 0 20px rgba(99, 102, 241, 0.15)',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-[#F8FAFC] text-slate-800 font-sans antialiased overflow-hidden selection:bg-indigo-100 selection:text-indigo-900 h-screen w-screen flex flex-col">

    @php
        $isSynced = isset($data['Tier 1']) && $data['Tier 1'] !== '-';
        
        // SKU Mode Logic
        $skuMode = $data['Config Model']['SkuMode'] ?? null;
        $skuLabel = 'Unknown Mode';
        $skuStyle = 'bg-slate-100 text-slate-500 border-slate-200';
        
        if($skuMode === '0') {
            $skuLabel = 'Color Mode';
            $skuStyle = 'bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 text-white border-transparent shadow-md';
        } elseif($skuMode === '1') {
            $skuLabel = 'Black Mode';
            $skuStyle = 'bg-slate-900 text-white border-slate-700 shadow-md';
        } elseif($skuMode === '2') {
            $skuLabel = 'White Mode';
            $skuStyle = 'bg-white text-slate-700 border-slate-300 shadow-sm';
        }
    @endphp

    <div class="flex h-full w-full overflow-hidden max-w-[1920px] mx-auto shadow-2xl">
        
        <!-- Main Content Area: SCROLL REMOVED (1-PAGE VIEW) -->
        <main class="flex-1 flex flex-col h-full w-full p-3 md:p-4 relative overflow-hidden gap-3">
            
            <!-- Header -->
            <header class="flex justify-between items-end flex-none">
                <div class="flex flex-col gap-1.5">
                    <nav class="flex items-center gap-2 text-xs font-semibold">
                        <a href="{{ route('fleet', ['plant' => $plant]) }}" class="text-slate-400 hover:text-indigo-600 transition-colors flex items-center gap-1.5 bg-white px-2.5 py-1 rounded-md border border-slate-200 shadow-sm">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                            Fleet Dashboard
                        </a>
                        <span class="text-slate-300">/</span>
                        <span class="bg-indigo-50 text-indigo-700 px-2.5 py-1 rounded-md border border-indigo-100 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                            Node Profile
                        </span>
                        @if($isSynced)
                            <span class="ml-2 flex items-center gap-1 text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-1 rounded-md border border-emerald-100">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> ONLINE
                            </span>
                        @else
                            <span class="ml-2 flex items-center gap-1 text-[10px] font-bold text-rose-600 bg-rose-50 px-2 py-1 rounded-md border border-rose-100">
                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> OFFLINE
                            </span>
                        @endif
                    </nav>
                    <div>
                        <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight leading-none">Plant {{ $plant }} <span class="text-slate-400 font-light">| Line {{ $line }} | Side {{ $side }}</span></h2>
                    </div>
                </div>
                
                <div class="flex flex-col items-end gap-1.5">
                    <!-- Auth UI -->
                    @auth
                        <div class="flex items-center gap-3 bg-white p-1.5 px-3 rounded-lg shadow-sm border border-slate-200">
                            <span class="text-xs font-extrabold text-slate-700 uppercase">{{ auth()->user()->name }}</span>
                            <form method="POST" action="{{ route('logout') }}" class="m-0">
                                @csrf
                                <button type="submit" class="text-[10px] bg-rose-50 text-rose-600 hover:bg-rose-100 font-bold px-2 py-1 rounded transition-colors">Logout</button>
                            </form>
                        </div>
                    @else
                        <a href="{{ route('login') }}" class="flex items-center gap-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 p-1.5 px-3 rounded-lg shadow-sm border border-indigo-100 transition-colors text-xs font-bold">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                            Login
                        </a>
                    @endauth
                </div>
            </header>

            <!-- Status Flash Messages -->
            @if(session('success'))
                <div class="bg-emerald-50 text-emerald-600 px-4 py-2 rounded-xl text-xs font-bold border border-emerald-100 flex items-center gap-2 flex-none">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="bg-rose-50 text-rose-600 px-4 py-2 rounded-xl text-xs font-bold border border-rose-100 flex items-center gap-2 flex-none">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    {{ session('error') }}
                </div>
            @endif

            <!-- Top Row: Metric Cards Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 flex-none">
                
                <!-- Left Panel: Node Identity & Backup Status (Col 1-3) -->
                <div class="lg:col-span-3 flex flex-col gap-3 flex-none">
                    
                    <!-- Software Info -->
                    <div class="bg-white rounded-xl p-4 shadow-soft border border-slate-100 relative overflow-hidden flex-1">
                        <div class="absolute top-0 right-0 w-20 h-20 bg-indigo-50 rounded-full blur-2xl opacity-60 -mr-6 -mt-6"></div>
                        <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Software Info</h3>
                        
                        <div class="space-y-1.5 relative z-10 text-xs">
                            @if($skuMode !== null)
                            <div class="flex items-center justify-between border-b border-slate-50 pb-1 mb-1">
                                <span class="font-bold text-slate-800">Running Product</span>
                                <span class="text-[9px] font-black px-2 py-0.5 rounded-md border {{ $skuStyle }} uppercase tracking-wider">{{ $skuLabel }}</span>
                            </div>
                            @endif

                            <div class="flex items-center justify-between border-b border-slate-50 pb-1">
                                <span class="font-medium text-slate-500">Tier 1 / 2 Target</span>
                                <span class="font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">{{ $data['Tier 1'] ?? '-' }} / {{ $data['Tier 2'] ?? '-' }}</span>
                            </div>
                            <div class="flex items-center justify-between border-b border-slate-50 pb-1">
                                <span class="font-medium text-slate-500">solw</span>
                                <span class="font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded truncate max-w-[300px]" title="{{ $data['Version']['solw'] ?? '-' }}">{{ $data['Version']['solw'] ?? '-' }}</span>
                            </div>
                            <div class="flex items-center justify-between border-b border-slate-50 pb-1">
                                <span class="font-medium text-slate-500">MiddleWare</span>
                                <span class="font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">{{ $data['Version']['MiddleWare'] ?? '-' }}</span>
                            </div>
                            <div class="flex items-center justify-between border-b border-slate-50 pb-1">
                                <span class="font-medium text-slate-500">DIMR Version</span>
                                <span class="font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">{{ $data['Version']['DIMR'] ?? '-' }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="font-medium text-slate-500">IOC.bas</span>
                                <span class="font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">{{ $data['Version']['IOC.bas'] ?? '-' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- SFTP Backup Status -->
                    <div class="bg-white rounded-xl p-3 shadow-soft border border-slate-100 relative overflow-hidden flex-none">
                        <div class="flex justify-between items-center mb-2">
                            <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg>
                                SFTP Backup
                            </h3>
                            <button id="btn-refresh-backup" onclick="refreshBackupData()" class="text-slate-400 hover:text-indigo-600 transition-colors p-1 bg-slate-50 rounded" title="Fetch Latest Backups from SFTP">
                                <svg id="icon-refresh-backup" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                            </button>
                        </div>
                        <div id="backup-status-container" class="text-xs space-y-1.5">
                            <p class="text-[9px] text-slate-500 text-center py-1">Click refresh to fetch backup info</p>
                        </div>
                    </div>

                </div>

                <!-- Middle Panel: Model Name List (Col 4-7) -->
                <div class="bg-[#0f172a] rounded-xl p-4 shadow-glow border border-slate-800 lg:col-span-4 relative overflow-hidden flex flex-col">
                    <div class="absolute inset-0 opacity-[0.03]" style="background-image: radial-gradient(#fff 1px, transparent 1px); background-size: 16px 16px;"></div>
                    
                    <div class="flex justify-between items-center mb-2 relative z-10 border-b border-slate-800 pb-2 flex-none">
                        <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            Model Name List
                        </h3>
                        
                        <div class="flex items-center gap-2">
                            @auth
                                <button id="btn-refresh-sftp" type="button" onclick="fetchSftpOptions(true)" class="hidden text-slate-400 hover:text-white transition-colors p-1" title="Refresh Dropdowns from SFTP">
                                    <svg id="icon-refresh-sftp" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                                </button>
                                <button onclick="toggleConfigEdit()" id="btn-toggle-edit" class="text-[9px] font-bold bg-indigo-500/20 text-indigo-400 hover:bg-indigo-500/40 px-2 py-0.5 rounded border border-indigo-500/30 transition-colors shadow-sm">
                                    EDIT
                                </button>
                            @endauth
                        </div>
                    </div>
                    
                    <div class="relative z-10 flex-1 flex flex-col content-start h-full">
                        <div class="grid grid-cols-2 gap-2 mb-2">
                            @if(!empty($data['Config Model']))
                                @foreach($data['Config Model'] as $key => $file)
                                
                                @if($key === 'SkuMode') 
                                    @continue 
                                @endif

                                <!-- VIEW MODE BOX -->
                                <div class="config-view-box bg-slate-800/50 p-1.5 rounded-lg border border-slate-700/50 flex flex-col justify-center">
                                    <span class="text-[8px] font-bold text-slate-500 uppercase tracking-wider mb-0.5">{{ $key }}</span>
                                    <div class="flex items-center gap-1.5 overflow-hidden">
                                        <svg class="w-2.5 h-2.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                                        <span class="text-[10px] font-mono text-slate-300 truncate" title="{{ $file }}">{{ pathinfo($file, PATHINFO_FILENAME) }}</span>
                                    </div>
                                </div>

                                <!-- EDIT MODE BOX -->
                                <div class="config-edit-box hidden bg-slate-800 p-1.5 rounded-lg border border-indigo-500/40 flex flex-col justify-center shadow-[0_0_10px_rgba(99,102,241,0.1)] transition-all">
                                    <span class="text-[8px] font-bold text-indigo-400 uppercase tracking-wider mb-0.5">{{ $key }}</span>
                                    <select id="select-{{ str_replace(' ', '', $key) }}" data-key="{{ $key }}" data-current="{{ $file }}" class="config-dropdown w-full bg-slate-900 border border-slate-700 text-[10px] font-mono text-slate-300 rounded p-1 outline-none focus:border-indigo-500 cursor-pointer">
                                        <option value="{{ $file }}">{{ pathinfo($file, PATHINFO_FILENAME) }}</option>
                                    </select>
                                </div>
                                @endforeach
                            @else
                                <p class="text-xs font-mono text-slate-500 col-span-2">> No configuration data detected.</p>
                            @endif
                        </div>

                        <button id="btn-update-config" type="button" onclick="submitConfigUpdate()" class="hidden mt-auto w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 rounded-lg transition-colors text-[10px] shadow-md shadow-indigo-500/20 flex items-center justify-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg>
                            Save Configuration Data
                        </button>
                        
                        <form id="formUpdateConfig" action="{{ route('node.update-config') }}" method="POST" class="hidden">
                            @csrf
                            <input type="hidden" name="config_payload" id="hiddenConfigPayload">
                        </form>
                    </div>
                </div>

                <!-- Right Panel: Compact SSD Storage (Col 8-12) -->
                <div class="lg:col-span-5 bg-white rounded-xl p-4 shadow-soft border border-slate-100 flex flex-col min-h-0">
                    <h3 class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2 flex-none flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                        Storage Telemetry
                    </h3>
                    
                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-2 flex-1 content-start overflow-y-auto custom-scrollbar min-h-0 pr-1">
                        @if(!empty($data['SSD_Status']))
                            @foreach($data['SSD_Status'] as $ssd)
                                @php
                                    $usedSpace = $ssd['TotalSpaceGB'] - $ssd['RemainingSpaceGB'];
                                    $fillPercentage = ($usedSpace / $ssd['TotalSpaceGB']) * 100;
                                    $isStorageAlert = $fillPercentage > 80;
                                    $barGradient = $isStorageAlert ? 'from-rose-500 to-rose-600' : 'from-indigo-500 to-cyan-400';
                                    
                                    $wear = $ssd['PercentageUsed'];
                                    if ($wear > 80) {
                                        $wearStatus = 'Red Alert'; $wearDot = 'bg-rose-500'; $wearText = 'text-rose-600'; $wearBg = 'bg-rose-50 border-rose-200';
                                    } elseif ($wear > 50) {
                                        $wearStatus = 'Warning'; $wearDot = 'bg-orange-500'; $wearText = 'text-orange-600'; $wearBg = 'bg-orange-50 border-orange-200';
                                    } else {
                                        $wearStatus = 'Healthy'; $wearDot = 'bg-emerald-500'; $wearText = 'text-emerald-600'; $wearBg = 'bg-slate-50 border-slate-100';
                                    }
                                @endphp
                                
                                <div class="bg-slate-50/50 rounded-lg p-2.5 border border-slate-100 hover:shadow-sm transition-all flex flex-col justify-between">
                                    <div class="flex justify-between items-center mb-1.5">
                                        <div class="flex items-center gap-2">
                                            <div class="w-6 h-6 bg-white rounded flex items-center justify-center border border-slate-200 shadow-sm relative overflow-hidden shrink-0">
                                                <div class="absolute top-0 left-0 w-full h-0.5 bg-gradient-to-r from-indigo-500 to-cyan-400"></div>
                                                <span class="text-xs font-black text-slate-700">{{ $ssd['DriveLetter'] }}</span>
                                            </div>
                                            <div class="overflow-hidden">
                                                <h4 class="font-bold text-slate-900 text-[10px] truncate">Disk {{ $ssd['PhysicalDiskNumber'] }}</h4>
                                                <p class="text-[8px] font-semibold text-slate-400 uppercase truncate" title="{{ $ssd['DiskModel'] }}">{{ $ssd['DiskModel'] }}</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-1 bg-white px-1.5 py-0.5 rounded border border-slate-200 shrink-0">
                                            @if($ssd['TemperatureCelsius'] > 45)
                                                <span class="text-[9px] font-bold text-rose-600">{{ $ssd['TemperatureCelsius'] }}°C</span>
                                            @else
                                                <span class="text-[9px] font-bold text-emerald-600">{{ $ssd['TemperatureCelsius'] }}°C</span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="mb-1 flex justify-between items-end text-[9px]">
                                        <span class="font-extrabold {{ $isStorageAlert ? 'text-rose-600' : 'text-slate-700' }}">
                                            {{ number_format($usedSpace, 0) }} <span class="font-medium text-slate-400">GB Used</span>
                                        </span>
                                        <span class="font-medium text-slate-400">of {{ number_format($ssd['TotalSpaceGB'], 0) }}</span>
                                    </div>
                                    
                                    <div class="w-full bg-slate-200 rounded-full h-1 mb-2 overflow-hidden">
                                        <div class="h-full rounded-full bg-gradient-to-r {{ $barGradient }}" style="width: {{ $fillPercentage }}%"></div>
                                    </div>
                                    
                                    <div class="flex justify-between items-center text-[9px] font-semibold text-slate-500 {{ $wearBg }} rounded px-2 py-1 border">
                                        <span>Wear: {{ $wear }}%</span>
                                        <span class="flex items-center gap-1 {{ $wearText }}">
                                            <span class="w-1.5 h-1.5 rounded-full {{ $wearDot }}"></span> 
                                            {{ $wearStatus }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        @else
                            <div class="col-span-2 bg-slate-50 border border-dashed border-slate-200 rounded-xl p-4 flex items-center justify-center text-slate-400 text-xs">
                                No storage telemetry.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            
            <!-- Bottom Row: 3-Column Layout (Fills remaining space without scrolling) -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 flex-1 min-h-0">
                
                <!-- LEFT: Historical Table (Flex-contained to prevent page scroll) -->
                <div class="flex flex-col min-h-0 bg-white rounded-xl shadow-soft border border-slate-100 overflow-hidden">
                    <div class="px-4 py-2 border-b border-slate-100 flex items-center justify-between bg-slate-50/50 flex-none">
                        <h3 class="text-xs font-extrabold text-slate-800 flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Recent History
                        </h3>
                    </div>
                    <div class="flex-1 overflow-y-auto custom-scrollbar min-h-0">
                        <table class="w-full text-left text-[10px] whitespace-nowrap">
                            <thead class="bg-slate-50 text-slate-500 sticky top-0 z-10 shadow-sm">
                                <tr>
                                    <th class="px-3 py-2 font-bold uppercase tracking-wider text-[8px] bg-slate-50 border-b border-slate-200">Time</th>
                                    <th class="px-3 py-2 font-bold uppercase tracking-wider text-[8px] bg-slate-50 border-b border-slate-200">MW Ver</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse($history->take(15) as $log)
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="px-3 py-1.5 font-mono text-slate-500">{{ $log->created_at->format('m-d H:i') }}</td>
                                        <td class="px-3 py-1.5"><span class="bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded text-[9px] font-mono">{{ $log->version_middleware ?? 'Unknown' }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="2" class="px-3 py-4 text-center text-slate-400 text-[10px]">No data.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- MIDDLE: SSD Wear Graph -->
                <div class="flex flex-col min-h-0 bg-white rounded-xl shadow-soft border border-slate-100 overflow-hidden">
                    <div class="px-4 py-2 border-b border-slate-100 flex items-center justify-between bg-slate-50/50 flex-none gap-2">
                        <h3 class="text-xs font-extrabold text-slate-800 flex items-center gap-1.5 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5 text-cyan-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg>
                            SSD Wear Trend
                        </h3>
                        <div class="flex bg-slate-200/50 p-0.5 rounded flex-none">
                            <button onclick="updateSsdChart('hourly')" id="btn-ssd-hourly" class="ssd-chart-toggle px-2 py-0.5 text-[9px] font-bold rounded text-slate-500 hover:text-slate-700">1H</button>
                            <button onclick="updateSsdChart('daily')" id="btn-ssd-daily" class="ssd-chart-toggle px-2 py-0.5 text-[9px] font-bold rounded text-slate-500 hover:text-slate-700">1D</button>
                            <button onclick="updateSsdChart('weekly')" id="btn-ssd-weekly" class="ssd-chart-toggle px-2 py-0.5 text-[9px] font-bold rounded text-slate-500 hover:text-slate-700">1W</button>
                            <button onclick="updateSsdChart('monthly')" id="btn-ssd-monthly" class="ssd-chart-toggle px-2 py-0.5 text-[9px] font-bold rounded bg-white shadow-sm text-indigo-600">1M</button>
                        </div>
                    </div>
                    <div class="flex-1 p-2 relative min-h-0 w-full">
                        <canvas id="wearChart"></canvas>
                    </div>
                </div>

                <!-- RIGHT: GPU Telemetry Graph -->
                <div class="flex flex-col min-h-0 bg-white rounded-xl shadow-soft border border-slate-100 overflow-hidden">
                    <div class="px-4 py-2 border-b border-slate-100 flex items-center justify-between bg-slate-50/50 flex-none gap-2">
                        <h3 class="text-xs font-extrabold text-slate-800 flex items-center gap-1.5 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m14-6h2m-2 6h2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg>
                            GPU Status
                        </h3>
                        <div class="flex items-center gap-1.5">
                            <div class="flex bg-slate-200/50 p-0.5 rounded flex-none">
                                <button onclick="updateGpuChart('hourly')" id="btn-gpu-hourly" class="gpu-chart-toggle px-2 py-0.5 text-[9px] font-bold rounded text-slate-500 hover:text-slate-700">1H</button>
                                <button onclick="updateGpuChart('daily')" id="btn-gpu-daily" class="gpu-chart-toggle px-2 py-0.5 text-[9px] font-bold rounded bg-white shadow-sm text-indigo-600">1D</button>
                                <button onclick="updateGpuChart('weekly')" id="btn-gpu-weekly" class="gpu-chart-toggle px-2 py-0.5 text-[9px] font-bold rounded text-slate-500 hover:text-slate-700">1W</button>
                                <button onclick="updateGpuChart('monthly')" id="btn-gpu-monthly" class="gpu-chart-toggle px-2 py-0.5 text-[9px] font-bold rounded text-slate-500 hover:text-slate-700">1M</button>
                            </div>
                            <select id="gpuMetricSelect" onchange="updateGpuChart(activeGpuTimeframe)" class="bg-white text-[9px] font-bold text-slate-600 border border-slate-200 rounded p-0.5 outline-none">
                                <option value="clock">Clock</option>
                                <option value="temp">Temperature</option>
                                <option value="power">Power</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex-1 p-2 relative min-h-0 w-full">
                        <canvas id="gpuChart"></canvas>
                    </div>
                </div>
            </div>
            
        </main>
    </div>

    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 10px; }
    </style>

    <script>
        const rawData = @json(isset($chartData) ? $chartData : $history);
        const currentPlant = "{{ $plant }}";
        const currentLine = "{{ $line }}";
        const currentSide = "{{ $side }}";
        
        let autoRefreshInterval = setInterval(() => window.location.reload(), 3600000); 

        // SFTP Backup Logic
        const expectedBackupFile = `${currentLine}${currentSide}.zip`;
        const backupCacheKey = `sftp_backups_plant_${currentPlant}`;

        window.addEventListener('DOMContentLoaded', () => {
            loadBackupDataFromMemory();
        });

        function loadBackupDataFromMemory() {
            const cached = sessionStorage.getItem(backupCacheKey);
            if (cached) { renderBackupUI(JSON.parse(cached)); }
        }

        async function refreshBackupData() {
            const btn = document.getElementById('btn-refresh-backup');
            const icon = document.getElementById('icon-refresh-backup');
            const container = document.getElementById('backup-status-container');
            
            icon.classList.add('animate-[spin_1s_linear_infinite]');
            btn.disabled = true;
            container.innerHTML = `<p class="text-[9px] text-slate-500 text-center py-1 animate-pulse">Connecting to SFTP...</p>`;

            try {
                const response = await fetch(`/api/sftp/backups/${currentPlant}`);
                const result = await response.json();
                
                if(result.status === 'success') {
                    sessionStorage.setItem(backupCacheKey, JSON.stringify(result.data));
                    renderBackupUI(result.data);
                } else {
                    container.innerHTML = `<p class="text-[9px] text-rose-500 text-center py-1">Failed to connect to SFTP</p>`;
                }
            } catch (e) {
                container.innerHTML = `<p class="text-[9px] text-rose-500 text-center py-1">Network Error</p>`;
            }

            icon.classList.remove('animate-[spin_1s_linear_infinite]');
            btn.disabled = false;
        }

        function renderBackupUI(allBackups) {
            const myBackup = allBackups.find(b => b.filename.toLowerCase() === expectedBackupFile.toLowerCase());
            const container = document.getElementById('backup-status-container');
            
            if (myBackup) {
                container.innerHTML = `
                    <div class="flex items-center justify-between border-b border-slate-50 pb-1">
                        <span class="font-medium text-slate-500">Filename</span>
                        <span class="text-[10px] font-black text-slate-800">${myBackup.filename}</span>
                    </div>
                    <div class="flex items-center justify-between border-b border-slate-50 pb-1 mt-1">
                        <span class="font-medium text-slate-500">Changed</span>
                        <span class="text-[10px] font-bold text-indigo-600">${myBackup.changed_date}</span>
                    </div>
                    <div class="flex items-center justify-between mt-1">
                        <span class="font-medium text-slate-500">Size</span>
                        <span class="text-[10px] font-bold text-slate-800">${myBackup.size}</span>
                    </div>
                `;
            } else {
                container.innerHTML = `
                    <div class="flex items-center justify-center p-1.5 bg-rose-50 border border-rose-100 rounded-lg mt-1">
                        <span class="text-[9px] font-bold text-rose-500 text-center">No backup found for <br>${expectedBackupFile}</span>
                    </div>
                `;
            }
        }

        // Model Name List Edit Logic
        let sftpOptionsCache = null;

        async function fetchSftpOptions(forceRefresh = false) {
            const icon = document.getElementById('icon-refresh-sftp');
            if(icon) icon.classList.add('animate-[spin_1s_linear_infinite]');
            try {
                const response = await fetch(`/api/sftp/config-options/${currentPlant}`);
                sftpOptionsCache = await response.json();
                populateDropdowns();
            } catch (error) { alert("Network error: Could not load dropdown options from SFTP."); }
            if(icon) icon.classList.remove('animate-[spin_1s_linear_infinite]');
        }

        function toggleConfigEdit() {
            const viewBoxes = document.querySelectorAll('.config-view-box');
            const editBoxes = document.querySelectorAll('.config-edit-box');
            const updateBtn = document.getElementById('btn-update-config');
            const refreshBtn = document.getElementById('btn-refresh-sftp');
            const toggleBtn = document.getElementById('btn-toggle-edit');
            
            const isEditing = !updateBtn.classList.contains('hidden');
            
            if(!isEditing) {
                clearInterval(autoRefreshInterval);
                viewBoxes.forEach(el => el.classList.add('hidden'));
                editBoxes.forEach(el => el.classList.remove('hidden'));
                updateBtn.classList.remove('hidden');
                refreshBtn.classList.remove('hidden');
                toggleBtn.innerText = "CANCEL";
                toggleBtn.classList.replace('text-indigo-400', 'text-rose-400');
                if(!sftpOptionsCache) fetchSftpOptions(); else populateDropdowns();
            } else {
                autoRefreshInterval = setInterval(() => window.location.reload(), 3600000);
                viewBoxes.forEach(el => el.classList.remove('hidden'));
                editBoxes.forEach(el => el.classList.add('hidden'));
                updateBtn.classList.add('hidden');
                refreshBtn.classList.add('hidden');
                toggleBtn.innerText = "EDIT";
                toggleBtn.classList.replace('text-rose-400', 'text-indigo-400');
            }
        }

        function populateDropdowns() {
            if(!sftpOptionsCache) return;
            Object.keys(sftpOptionsCache).forEach(key => {
                const selectId = `select-${key.replace(/ /g, '')}`;
                const select = document.getElementById(selectId);
                if(select) {
                    const currentVal = select.getAttribute('data-current');
                    select.innerHTML = '';
                    let hasCurrent = false;
                    if(sftpOptionsCache[key] && sftpOptionsCache[key].length > 0) {
                        sftpOptionsCache[key].forEach(opt => {
                            if(opt === currentVal) hasCurrent = true;
                            const displayOpt = opt.includes('.') ? opt.substring(0, opt.lastIndexOf('.')) : opt;
                            select.innerHTML += `<option value="${opt}" ${opt === currentVal ? 'selected' : ''}>${displayOpt}</option>`;
                        });
                    }
                    if(!hasCurrent && currentVal) {
                        const currentDisplay = currentVal.includes('.') ? currentVal.substring(0, currentVal.lastIndexOf('.')) : currentVal;
                        select.innerHTML = `<option value="${currentVal}" selected>${currentDisplay} (Current)</option>` + select.innerHTML;
                    }
                }
            });
        }

        function submitConfigUpdate() {
            const payload = { "Plant": "{{ $plant }}", "Line": "{{ $line }}", "Side": "{{ $side }}", "Config Model": { "SkuMode": "{{ $data['Config Model']['SkuMode'] ?? '0' }}" } };
            document.querySelectorAll('.config-dropdown').forEach(select => { payload["Config Model"][select.dataset.key] = select.value; });
            document.getElementById('hiddenConfigPayload').value = JSON.stringify(payload);
            document.getElementById('formUpdateConfig').submit();
        }

        // Chart Rendering Script
        const palette = ['#6366f1', '#06b6d4', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6'];
        let wearChart;
        let gpuChart;
        let activeTimeframe = 'monthly';

        function processChartData(timeframe) {
            const now = new Date();
            let cutoff = new Date();
            if (timeframe === 'hourly') cutoff.setHours(now.getHours() - 1);
            if (timeframe === 'daily') cutoff.setDate(now.getDate() - 1);
            if (timeframe === 'weekly') cutoff.setDate(now.getDate() - 7);
            if (timeframe === 'monthly') cutoff.setMonth(now.getMonth() - 1);

            const filteredLogs = rawData.filter(log => new Date(log.created_at) >= cutoff);
            const ssdGroups = {};
            
            filteredLogs.forEach(log => {
                const time = new Date(log.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                log.ssd_logs.forEach(ssd => {
                    const letter = ssd.drive_letter;
                    if (!ssdGroups[letter]) ssdGroups[letter] = { data: [], labels: [] };
                    ssdGroups[letter].labels.push(time);
                    ssdGroups[letter].data.push(ssd.percentage_used);
                });
            });

            const datasets = [];
            let masterLabels = [];
            let colorIndex = 0;

            for (const [drive, group] of Object.entries(ssdGroups)) {
                if (group.labels.length > masterLabels.length) masterLabels = group.labels;
                datasets.push({
                    label: `Drive ${drive}`, data: group.data, borderColor: palette[colorIndex % palette.length],
                    backgroundColor: palette[colorIndex % palette.length], borderWidth: 2, tension: 0.3, pointRadius: 0,
                    trendlineLinear: { colorMin: palette[colorIndex % palette.length], colorMax: palette[colorIndex % palette.length], lineStyle: "dotted", width: 2 }
                });
                colorIndex++;
            }
            return { labels: masterLabels, datasets: datasets };
        }

        function processGpuChartData(timeframe, metric) {
            const now = new Date();
            let cutoff = new Date();
            if (timeframe === 'hourly') cutoff.setHours(now.getHours() - 1);
            if (timeframe === 'daily') cutoff.setDate(now.getDate() - 1);
            if (timeframe === 'weekly') cutoff.setDate(now.getDate() - 7);
            if (timeframe === 'monthly') cutoff.setMonth(now.getMonth() - 1);

            const filteredLogs = rawData.filter(log => new Date(log.created_at) >= cutoff);
            const labels = [];
            const data = [];
            
            filteredLogs.forEach(log => {
                labels.push(new Date(log.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}));
                if (metric === 'temp') data.push(log.gpu_temp || 0);
                if (metric === 'power') data.push(log.gpu_power || 0);
                if (metric === 'clock') data.push(log.gpu_clock || 0);
            });

            let yAxisLabel = metric === 'temp' ? 'Temp (°C)' : (metric === 'power' ? 'Power (W)' : 'Clock (MHz)');
            return { 
                labels, yAxisLabel,
                datasets: [{ label: `GPU ${metric.toUpperCase()}`, data: data, borderColor: '#a855f7', backgroundColor: '#a855f7', borderWidth: 2, tension: 0.3, pointRadius: 0 }]
            };
        }

        let activeSsdTimeframe = 'monthly';
        let activeGpuTimeframe = 'daily';

        function updateSsdChart(timeframe) {
            activeSsdTimeframe = timeframe;
            
            document.querySelectorAll('.ssd-chart-toggle').forEach(btn => {
                btn.classList.remove('bg-white', 'shadow-sm', 'text-indigo-600');
                btn.classList.add('text-slate-500');
            });
            
            const activeBtn = document.getElementById(`btn-ssd-${timeframe}`);
            if(activeBtn) {
                activeBtn.classList.add('bg-white', 'shadow-sm', 'text-indigo-600');
                activeBtn.classList.remove('text-slate-500');
            }

            const ssdData = processChartData(timeframe);
            const ctxSsd = document.getElementById('wearChart').getContext('2d');
            
            if (wearChart) {
                wearChart.data = ssdData; 
                wearChart.update();
            } else {
                wearChart = new Chart(ctxSsd, {
                    type: 'line', data: ssdData,
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, font: { size: 9 } } } }, scales: { y: { beginAtZero: true, title: { display: true, text: 'Wear (%)', font: {size: 9} }, ticks: { font: { size: 8 } } }, x: { ticks: { maxTicksLimit: 6, font: { size: 8 } }, grid: { display: false } } } }
                });
            }
        }

        function updateGpuChart(timeframe) {
            activeGpuTimeframe = timeframe;
            
            document.querySelectorAll('.gpu-chart-toggle').forEach(btn => {
                btn.classList.remove('bg-white', 'shadow-sm', 'text-indigo-600');
                btn.classList.add('text-slate-500');
            });
            
            const activeBtn = document.getElementById(`btn-gpu-${timeframe}`);
            if(activeBtn) {
                activeBtn.classList.add('bg-white', 'shadow-sm', 'text-indigo-600');
                activeBtn.classList.remove('text-slate-500');
            }

            const metric = document.getElementById('gpuMetricSelect').value;
            const gpuDataObj = processGpuChartData(timeframe, metric);
            const ctxGpu = document.getElementById('gpuChart').getContext('2d');
            
            if (gpuChart) {
                gpuChart.data = gpuDataObj; 
                gpuChart.options.scales.y.title.text = gpuDataObj.yAxisLabel; 
                gpuChart.update();
            } else {
                gpuChart = new Chart(ctxGpu, {
                    type: 'line', data: gpuDataObj,
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, font: { size: 9 } } } }, scales: { y: { title: { display: true, text: gpuDataObj.yAxisLabel, font: {size: 9} }, ticks: { font: { size: 8 } } }, x: { ticks: { maxTicksLimit: 6, font: { size: 8 } }, grid: { display: false } } } }
                });
            }
        }

        window.addEventListener('DOMContentLoaded', () => { 
            updateSsdChart('monthly'); 
            updateGpuChart('daily'); 
        });
    </script>
</body>
</html>