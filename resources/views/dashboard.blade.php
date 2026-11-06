<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plant {{ $plant }} Fleet View</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
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
                <p class="text-slate-500 text-sm font-medium ml-11">Monitoring 12 Lines • Dual-Side Topologies</p>
            </div>
            
            <div class="flex items-center gap-4 text-xs font-semibold bg-white p-2 rounded-xl shadow-sm border border-slate-200">
                <span class="flex items-center gap-1.5 px-2"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Online</span>
                <span class="flex items-center gap-1.5 px-2"><span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span> Offline</span>
            </div>
        </header>

        <!-- 24-Node Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4">
            @foreach($grid as $node)
                <div class="bg-white rounded-2xl p-4 shadow-soft border {{ $node['status'] ? 'border-emerald-100 hover:border-indigo-300' : 'border-rose-100 opacity-80' }} transition-colors relative overflow-hidden flex flex-col h-full">
                    
                    <!-- Top row: Line, Side, and Status Indicator -->
                    <div class="flex justify-between items-center mb-3 pb-3 border-b border-slate-100">
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block leading-none mb-1">Line {{ $node['line'] }}</span>
                            <span class="text-lg font-extrabold text-slate-800 leading-none">Side {{ $node['side'] }}</span>
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

                    <!-- SSD List -->
                    <div class="flex-1 flex flex-col gap-2">
                        @if($node['status'] && isset($node['data']['SSD_Status']))
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
                                @endphp
                                
                                <div class="flex items-center justify-between bg-slate-50 rounded-lg p-2 border border-slate-100 text-xs">
                                    <div class="flex items-center gap-2">
                                        <!-- Wear Health Dot -->
                                        <span class="relative flex h-2 w-2">
                                            <span class="absolute inline-flex h-full w-full rounded-full opacity-75 {{ $pulse }}"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 {{ $dot }}"></span>
                                        </span>
                                        <span class="font-bold text-slate-700">{{ $ssd['DriveLetter'] }}</span>
                                    </div>
                                    <div class="font-medium text-slate-500">
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
                </div>
            @endforeach
        </div>
    </div>

    <script>
        setInterval(() => window.location.reload(), 10000);
    </script>
</body>
</html>