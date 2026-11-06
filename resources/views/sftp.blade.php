<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SFTP Explorer</title>
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
</head>
<body class="bg-[#F8FAFC] text-slate-800 flex items-center justify-center min-h-screen p-4 md:p-8">

    <div class="bg-white p-8 rounded-3xl shadow-lg border border-slate-100 w-full max-w-4xl">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6 border-b border-slate-100 pb-4">
            <div>
                <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">SFTP <span class="text-indigo-500">Explorer</span></h1>
                <p class="text-slate-500 text-sm mt-1">Logged in as <span class="font-bold text-indigo-600 uppercase">{{ auth()->user()->name }}</span></p>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="m-0">
                @csrf
                <button type="submit" class="bg-rose-50 text-rose-600 hover:bg-rose-100 font-bold py-2 px-4 rounded-xl transition-colors text-xs border border-rose-200">
                    Log Out
                </button>
            </form>
        </div>

        <!-- SFTP Controls -->
        <form method="POST" action="{{ route('secure.test') }}" class="flex gap-3 mb-6">
            @csrf
            <div class="flex-1">
                <label class="block text-xs font-bold text-slate-700 mb-1">Directory Path</label>
                <input type="text" name="path" value="{{ $path }}" class="w-full px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all text-sm" placeholder="e.g. /Software/Model">
            </div>
            <div class="flex items-end">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-6 rounded-xl transition-colors shadow-md flex items-center gap-2 text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    Refresh
                </button>
            </div>
        </form>

        <!-- Error Message -->
        @if($error)
            <div class="mb-5 bg-rose-50 border border-rose-100 text-rose-600 px-4 py-3 rounded-xl text-sm font-bold flex items-center gap-2">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                {{ $error }}
            </div>
        @endif

        <!-- File List -->
        <div class="border border-slate-200 rounded-xl overflow-hidden bg-slate-50 shadow-inner">
            <div class="px-4 py-3 bg-slate-100 border-b border-slate-200 text-xs font-bold text-slate-500 uppercase tracking-wider flex items-center">
                <div class="w-1/2">Name</div>
                <div class="w-1/4 text-right pr-4">Modified Date</div>
                <div class="w-1/4 text-right">Type</div>
            </div>
            <ul class="divide-y divide-slate-200 h-96 overflow-y-auto">
                @forelse($contents as $item)
                    <li class="px-4 py-3 flex items-center hover:bg-white transition-colors cursor-default">
                        
                        <!-- Name Column -->
                        <div class="flex items-center gap-3 w-1/2 truncate pr-4">
                            @if($item['type'] === 'folder')
                                <svg class="w-5 h-5 text-indigo-400 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"></path></svg>
                            @else
                                <svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                            @endif
                            <span class="text-sm font-medium text-slate-700 truncate" title="{{ $item['name'] }}">{{ $item['name'] }}</span>
                        </div>
                        
                        <!-- Date Column -->
                        <div class="w-1/4 text-right pr-4">
                            <span class="text-[10px] font-mono font-semibold text-slate-500 bg-slate-100 px-2 py-1 rounded">{{ $item['date'] }}</span>
                        </div>
                        
                        <!-- Type Column -->
                        <div class="w-1/4 text-right">
                            <span class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">{{ $item['type'] }}</span>
                        </div>
                    </li>
                @empty
                    @if(!$error)
                        <li class="px-4 py-8 flex flex-col items-center justify-center text-slate-400 h-full gap-2">
                            <svg class="w-8 h-8 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                            <span class="text-sm font-medium">No files or folders found.</span>
                        </li>
                    @endif
                @endforelse
            </ul>
        </div>
        
        <div class="mt-6 text-center">
            <a href="{{ route('settings') }}" class="text-xs font-bold text-indigo-500 hover:text-indigo-600 transition-colors">← Back to Settings</a>
        </div>
    </div>

</body>
</html>