@props([
    'text',
    'position' => 'top'
])
@php
$posClasses = match($position) {
    'bottom' => 'top-full left-1/2 -translate-x-1/2 mt-2',
    'left' => 'right-full top-1/2 -translate-y-1/2 mr-2',
    'right' => 'left-full top-1/2 -translate-y-1/2 ml-2',
    default => 'bottom-full left-1/2 -translate-x-1/2 mb-2',
};
@endphp
<div class="relative inline-block" x-data="{ open: false }">
    <div @mouseenter="open = true" @mouseleave="open = false" @focus="open = true" @blur="open = false">
        {{ $slot }}
    </div>
    <div 
        x-show="open" 
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute z-[1400] whitespace-nowrap px-2.5 py-1 text-xs font-semibold text-white bg-[var(--brand-navy-900)] rounded-md shadow-md pointer-events-none {{ $posClasses }}"
        style="display: none;"
        role="tooltip"
    >
        {{ $text }}
    </div>
</div>
