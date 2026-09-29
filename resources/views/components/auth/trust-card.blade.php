@props(['icon', 'title', 'subtitle'])

<div class="flex min-h-16 items-center justify-center gap-3 rounded-xl bg-white/[0.82] px-4 py-3 shadow-[0_16px_35px_rgba(37,71,118,0.10)]">
    <span class="flex h-8 w-8 items-center justify-center text-[#003DA3]">
        @if ($icon === 'shield-check')
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 3l7 3v5c0 4.6-2.9 8.4-7 10-4.1-1.6-7-5.4-7-10V6l7-3Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                <path d="m9 12 2 2 4-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        @elseif ($icon === 'lock')
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M7 10V8a5 5 0 0 1 10 0v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="2"/>
            </svg>
        @else
            <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M17.5 18H8a4 4 0 1 1 .5-8 5.5 5.5 0 0 1 10.4 2.2A2.9 2.9 0 0 1 17.5 18Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        @endif
    </span>
    <p class="text-xs font-bold leading-tight text-[#003DA3]">
        {{ $title }}<br>{{ $subtitle }}
    </p>
</div>
