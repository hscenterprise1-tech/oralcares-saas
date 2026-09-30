<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'OralCares') }}</title>

        {{--
            Two families, because the design system only ever resolves to two.

            Six were being fetched on every page: Geist, Inter, Manrope, Outfit,
            Plus Jakarta Sans and JetBrains Mono. `tokens.json` maps `font-sans`
            and `font-display` to Inter and `font-mono` to JetBrains Mono — the
            other four were downloaded, parsed and never referenced by a single
            class in the product.

            `preload` on the stylesheet so the request starts with the document
            rather than after the CSS that links it.
        --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link
            rel="stylesheet"
            href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap"
        >

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased text-slate-900 bg-ovs-mesh">
        @inertia
    </body>
</html>
