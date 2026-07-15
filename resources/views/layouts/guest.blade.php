<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'OralCares Portal') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=Outfit:400,500,600,700,800|Inter:300,400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-slate-900 antialiased bg-slate-50">
        <div class="min-h-screen flex flex-col sm:justify-center items-center px-4 sm:px-0 py-8 relative">
            <div class="w-full sm:max-w-md">
                <!-- Brand logo area -->
                <div class="flex flex-col items-center mb-6">
                    <img src="{{ asset('images/oral-cares-logo.png') }}" alt="OralCares Logo" class="h-16 w-16 object-contain" />
                    <h1 class="text-slate-900 text-2xl font-extrabold tracking-tight mt-3">Oral<span class="text-cyan-500">Cares</span></h1>
                    <p class="text-xs text-slate-500 mt-1 uppercase tracking-wider font-bold">Dental Command Center Login</p>
                </div>

                <!-- Main Card -->
                <div class="bg-white border border-slate-200/80 p-8 shadow-xl rounded-2xl">
                    {{ $slot }}
                </div>

                <!-- Footer -->
                <div class="text-center mt-8">
                    <p class="text-xs text-slate-400 font-semibold">&copy; {{ date('Y') }} OralCares. All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
</html>
