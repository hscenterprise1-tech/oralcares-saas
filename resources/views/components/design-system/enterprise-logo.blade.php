@props([
    'size' => 'md', // sm, md, lg
    'showText' => true
])
@php
$dimensions = match ($size) {
    'sm' => 'h-7 w-7',
    'lg' => 'h-12 w-12',
    default => 'h-9 w-9',
};
$textSizes = match ($size) {
    'sm' => 'text-lg',
    'lg' => 'text-3xl',
    default => 'text-xl',
};
@endphp
<div {{ $attributes->merge(['class' => 'inline-flex items-center space-x-3 select-none']) }}>
    <!-- OralCares Brand Logo Vector (Tooth Contour + OC Monogram) -->
    <svg class="{{ $dimensions }} shrink-0" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- Deep Navy Left & Bottom Tooth Stroke -->
        <path d="M 52,18 C 38,12 14,20 14,46 C 14,76 32,90 40,90 C 48,90 50,72 60,72 C 70,72 72,90 80,90 C 85,90 88,82 88,72" 
              stroke="#0A2540" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" />
        <!-- Vibrant Teal Top-Right Crown Swoop -->
        <path d="M 48,18 C 58,22 72,12 86,22 C 92,30 92,50 88,70" 
              stroke="#00A8B5" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" />
        <!-- Inner 'O' Monogram Ring (Teal) -->
        <circle cx="40" cy="46" r="11" stroke="#00A8B5" stroke-width="7" />
        <!-- Inner 'C' Monogram Arc (Navy) -->
        <path d="M 69,38 A 11,11 0 1,0 69,54" stroke="#0A2540" stroke-width="7" stroke-linecap="round" />
    </svg>

    @if($showText)
        <span class="{{ $textSizes }} font-extrabold tracking-tight text-[#0A2540] dark:text-white">
            Oral<span class="text-[#00A8B5]">Cares</span>
        </span>
    @endif
</div>
