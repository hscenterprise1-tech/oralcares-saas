@props([
    'variant' => 'danger', // danger, success, info
    'title' => null
])
@php
$styles = match ($variant) {
    'success' => 'bg-emerald-50 border-l-4 border-[#22C55E] text-[#15803D]',
    'info' => 'bg-blue-50 border-l-4 border-[#0F5FFF] text-[#1D4ED8]',
    default => 'bg-red-50 border-l-4 border-[#EF4444] text-[#B91C1C]',
};
@endphp
<div {{ $attributes->merge(['class' => "p-4 rounded-r-xl text-sm font-medium flex items-start space-x-3 {$styles}"]) }} role="alert">
    <div class="shrink-0 mt-0.5">
        @if($variant === 'danger')
            <svg class="w-4 h-4 text-[#EF4444]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        @else
            <svg class="w-4 h-4 text-[#22C55E]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        @endif
    </div>
    <div class="flex-1">
        @if($title)
            <p class="font-semibold mb-0.5">{{ $title }}</p>
        @endif
        <p>{{ $slot }}</p>
    </div>
</div>
