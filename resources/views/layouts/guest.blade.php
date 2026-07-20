<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" id="app-html" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'OralCares Portal') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <script>
            // Sync theme choice from localStorage
            (function() {
                const storedTheme = localStorage.getItem('theme') || 'light';
                document.documentElement.setAttribute('data-theme', storedTheme);
            })();
        </script>
    </head>
    <body class="bg-[var(--color-bg-app)] text-[var(--color-text-main)] antialiased transition-colors duration-250 min-h-screen">
        {{ $slot }}
    </body>
</html>
