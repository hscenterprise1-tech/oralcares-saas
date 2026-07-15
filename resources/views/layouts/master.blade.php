<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'OralCares Master Portal' }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=Outfit:400,500,600,700,800|Inter:300,400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-slate-50/50 text-slate-900">
        <div class="min-h-screen flex">
            <!-- Sidebar -->
            <aside class="w-64 bg-slate-900 text-slate-300 flex flex-col justify-between border-r border-slate-800/40 shrink-0">
                <div>
                    <!-- Brand Area -->
                    <div class="h-16 px-5 flex items-center bg-slate-950 border-b border-slate-800/40">
                        <a href="{{ route('master.dashboard') }}" class="flex items-center space-x-2.5">
                            <img src="{{ asset('images/oral-cares-logo.png') }}" alt="OralCares Logo" class="h-8 w-8 object-contain" />
                            <span class="text-white text-lg font-bold tracking-tight">Oral<span class="text-cyan-400">Cares</span></span>
                            <span class="bg-cyan-500/10 text-cyan-400 text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider">Master</span>
                        </a>
                    </div>

                    <!-- Navigation Links -->
                    <nav class="p-4 space-y-1.5">
                        <a href="{{ route('master.dashboard') }}"
                           class="flex items-center space-x-3 px-4 py-2.5 rounded-lg text-sm font-medium transition duration-150 ease-in-out {{ request()->routeIs('master.dashboard') ? 'bg-cyan-500/10 text-cyan-400 font-bold border-l-2 border-cyan-500 pl-3.5 shadow-sm' : 'hover:bg-slate-800/50 hover:text-white' }}">
                            <svg class="w-5 h-5 opacity-85" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4zM14 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2v-4z" />
                            </svg>
                            <span>Dashboard</span>
                        </a>

                        <a href="{{ route('master.clinics.index') }}"
                           class="flex items-center space-x-3 px-4 py-2.5 rounded-lg text-sm font-medium transition duration-150 ease-in-out {{ request()->routeIs('master.clinics.*') ? 'bg-cyan-500/10 text-cyan-400 font-bold border-l-2 border-cyan-500 pl-3.5 shadow-sm' : 'hover:bg-slate-800/50 hover:text-white' }}">
                            <svg class="w-5 h-5 opacity-85" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                            <span>Clinics</span>
                        </a>

                        <a href="{{ route('master.leads.index') }}"
                           class="flex items-center space-x-3 px-4 py-2.5 rounded-lg text-sm font-medium transition duration-150 ease-in-out {{ request()->routeIs('master.leads.*') ? 'bg-cyan-500/10 text-cyan-400 font-bold border-l-2 border-cyan-500 pl-3.5 shadow-sm' : 'hover:bg-slate-800/50 hover:text-white' }}">
                            <svg class="w-5 h-5 opacity-85" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                            <span>Leads</span>
                        </a>
                    </nav>
                </div>

                <!-- User Action Section -->
                <div class="p-4 border-t border-slate-800/40 bg-slate-950">
                    <div class="flex flex-col space-y-3">
                        <div class="flex items-center space-x-3">
                            @php
                                $names = explode(' ', Auth::user()->name);
                                $initials = strtoupper(substr($names[0] ?? '', 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : ''));
                            @endphp
                            <div class="w-9 h-9 rounded-full bg-cyan-500/20 text-cyan-400 flex items-center justify-center text-xs font-extrabold uppercase shrink-0 border border-cyan-500/30">
                                {{ $initials }}
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-white truncate">{{ Auth::user()->name }}</div>
                                <div class="text-[10px] text-cyan-400 font-bold uppercase tracking-wider mt-0.5">Master Admin</div>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full flex items-center justify-center space-x-2 px-3 py-2 bg-rose-600/10 hover:bg-rose-600/20 text-rose-400 hover:text-rose-300 text-xs font-semibold rounded-lg border border-rose-500/20 transition duration-150 ease-in-out cursor-pointer">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                                <span>Log Out</span>
                            </button>
                        </form>
                    </div>
                </div>
            </aside>

            <!-- Main Content Area -->
            <div class="flex-1 flex flex-col min-h-screen">
                <!-- Header -->
                @php
                    $hour = date('H');
                    if ($hour < 12) {
                        $greeting = 'Good morning';
                    } elseif ($hour < 17) {
                        $greeting = 'Good afternoon';
                    } else {
                        $greeting = 'Good evening';
                    }
                @endphp
                <header class="h-16 bg-white border-b border-slate-100 px-8 flex items-center justify-between shrink-0 shadow-sm/5">
                    <div>
                        @if(request()->routeIs('master.dashboard'))
                            <h2 class="text-sm font-semibold text-slate-500 tracking-tight">
                                {{ $greeting }}, <span class="text-slate-800 font-bold">{{ Auth::user()->name }}</span>
                            </h2>
                        @else
                            <h2 class="text-base font-bold text-slate-800 tracking-tight">
                                {{ $header ?? 'OralCares Master Portal' }}
                            </h2>
                        @endif
                    </div>
                    <div class="flex items-center space-x-4">
                        <span class="text-xs text-slate-400 font-medium">{{ now()->format('l, F j, Y') }}</span>
                    </div>
                </header>

                <!-- Page Content -->
                <main class="flex-1 p-8 bg-slate-50/50">
                    <!-- Session Flash Messages -->
                    @if (session('success'))
                        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200/50 text-emerald-800 rounded-xl text-sm font-medium flex items-center space-x-2 shadow-sm">
                            <svg class="w-5 h-5 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>{{ session('success') }}</span>
                        </div>
                    @endif

                    @if (session('error'))
                        <div class="mb-6 p-4 bg-rose-50 border border-rose-200/50 text-rose-800 rounded-xl text-sm font-medium flex items-center space-x-2 shadow-sm">
                            <svg class="w-5 h-5 text-rose-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <span>{{ session('error') }}</span>
                        </div>
                    @endif

                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
