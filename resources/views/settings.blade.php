<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings</title>
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
</head>
<body class="bg-[#F8FAFC] text-slate-800 flex items-center justify-center min-h-screen relative">

    <div class="bg-white p-8 rounded-3xl shadow-lg border border-slate-100 w-full max-w-md">
        
        <!-- Top Navigation Actions -->
        <div class="mb-6 flex justify-between items-center">
            <a href="{{ route('fleet') }}" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-400 hover:text-indigo-500 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m7 7h18"></path></svg>
                Back to Dashboard
            </a>
            
            <!-- New SFTP Button -->
            <a href="{{ route('secure.test') }}" class="inline-flex items-center gap-1.5 text-xs font-bold text-indigo-600 hover:text-indigo-700 bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-lg transition-colors border border-indigo-100 shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>
                SFTP Explorer
            </a>
        </div>

        <div class="mb-8">
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Account <span class="text-indigo-500">Settings</span></h1>
            <p class="text-slate-500 text-sm mt-1">Change the password for <strong class="text-slate-700 uppercase">{{ auth()->user()->name }}</strong>.</p>
        </div>

        <!-- Success Message -->
        @if(session('success'))
            <div class="mb-5 bg-emerald-50 border border-emerald-100 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold flex items-center gap-2">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('settings.password') }}" class="space-y-4">
            @csrf
            
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Current Password</label>
                <input type="password" name="current_password" required class="w-full px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all text-sm" placeholder="••••••••">
                @error('current_password')
                    <p class="text-rose-500 text-xs font-bold mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-2 border-t border-slate-100">
                <label class="block text-xs font-bold text-slate-700 mb-1">New Password <span class="text-slate-400 font-normal">(Min. 8 characters)</span></label>
                <input type="password" name="new_password" required class="w-full px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all text-sm" placeholder="••••••••">
                @error('new_password')
                    <p class="text-rose-500 text-xs font-bold mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Confirm New Password</label>
                <input type="password" name="new_password_confirmation" required class="w-full px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all text-sm" placeholder="••••••••">
            </div>

            <button type="submit" class="w-full bg-slate-900 hover:bg-black text-white font-bold py-3 rounded-xl transition-colors shadow-lg mt-4">
                Update Password
            </button>
        </form>
    </div>

</body>
</html>