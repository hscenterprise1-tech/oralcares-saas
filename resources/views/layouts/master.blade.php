<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'OralCares Master Portal' }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gray-50 text-gray-900">
        <div class="min-h-screen flex">
            <!-- Sidebar -->
            <aside class="w-64 bg-slate-900 text-slate-300 flex flex-col justify-between border-r border-slate-800 shrink-0">
                <div>
                    <!-- Brand Area -->
                    <div class="h-16 px-6 flex items-center bg-slate-950 border-b border-slate-800">
                        <div class="flex items-center space-x-2">
                            <span class="text-indigo-400 text-xl font-bold tracking-tight">OralCares</span>
                            <span class="bg-indigo-500/20 text-indigo-400 text-xs px-2 py-0.5 rounded-full font-medium">Master</span>
                        </div>
                    </div>

                    <!-- Navigation Links -->
                    <nav class="p-4 space-y-1">
                        <a href="{{ route('master.dashboard') }}"
                           class="flex items-center space-x-3 px-4 py-2.5 rounded-lg text-sm font-medium transition duration-150 ease-in-out {{ request()->routeIs('master.dashboard') ? 'bg-slate-800 text-white font-semibold' : 'hover:bg-slate-850 hover:text-white' }}">
                            <svg class="w-5 h-5 opacity-75" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4zM14 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2v-4z" />
                            </svg>
                            <span>Dashboard</span>
                        </a>

                        <a href="{{ route('master.clinics.index') }}"
                           class="flex items-center space-x-3 px-4 py-2.5 rounded-lg text-sm font-medium transition duration-150 ease-in-out {{ request()->routeIs('master.clinics.*') ? 'bg-slate-800 text-white font-semibold' : 'hover:bg-slate-850 hover:text-white' }}">
                            <svg class="w-5 h-5 opacity-75" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                            <span>Clinics</span>
                        </a>
                    </nav>
                </div>

                <!-- User Action Section -->
                <div class="p-4 border-t border-slate-800 bg-slate-950">
                    <div class="flex flex-col space-y-3">
                        <div>
                            <div class="text-sm font-medium text-white truncate">{{ Auth::user()->name }}</div>
                            <div class="text-xs text-indigo-400 font-semibold uppercase tracking-wider mt-0.5">Master Admin</div>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full flex items-center justify-center space-x-2 px-3 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold rounded-lg transition duration-150 ease-in-out">
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
                <header class="h-16 bg-white border-b border-gray-200 px-8 flex items-center justify-between shrink-0">
                    <div>
                        <h2 class="text-lg font-bold text-gray-800 tracking-tight">{{ $header ?? 'OralCares Master Portal' }}</h2>
                    </div>
                    <div class="flex items-center space-x-4">
                        <span class="text-xs text-gray-500 font-medium">{{ now()->format('l, F j, Y') }}</span>
                    </div>
                </header>

                <!-- Page Content -->
                <main class="flex-1 p-8">
                    <!-- Session Flash Messages -->
                    @if (session('success'))
                        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg text-sm font-medium flex items-center space-x-2">
                            <svg class="w-5 h-5 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>{{ session('success') }}</span>
                        </div>
                    @endif

                    @if (session('error'))
                        <div class="mb-6 p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-lg text-sm font-medium flex items-center space-x-2">
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
