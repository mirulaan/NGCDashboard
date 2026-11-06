<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Login</title>
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <script src="{{ asset('js/tailwindcss.js') }}"></script>
</head>
<body class="bg-[#F8FAFC] text-slate-800 flex items-center justify-center min-h-screen relative">

    <div class="bg-white p-8 rounded-3xl shadow-lg border border-slate-100 w-full max-w-md">
        
        <!-- Back Button -->
        <div class="mb-4">
            <a href="{{ route('fleet') }}" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-400 hover:text-indigo-500 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m7 7h18"></path></svg>
                Back to Dashboard
            </a>
        </div>

        <div class="text-center mb-8">
            <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">System<span class="text-indigo-500">Auth</span></h1>
            <p class="text-slate-500 text-sm mt-2">Enter your credentials to manage the fleet.</p>
        </div>

        <form method="POST" action="{{ route('login') }}" class="space-y-5">
            @csrf
            
            <div>
                <label class="block text-sm font-bold text-slate-700 mb-1">Login ID</label>
                <input type="text" name="name" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all" placeholder="username">
            </div>

            <div>
                <label class="block text-sm font-bold text-slate-700 mb-1">Password</label>
                <input type="password" name="password" required class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all" placeholder="••••••••">
            </div>

            @if($errors->any())
                <div class="text-rose-500 text-xs font-bold bg-rose-50 p-3 rounded-lg">
                    {{ $errors->first() }}
                </div>
            @endif

            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded-xl transition-colors shadow-lg shadow-indigo-200">
                Sign In
            </button>
        </form>
    </div>

</body>
</html>