@props([
    'alt' => 'OralCares Logo'
])
<img 
    src="{{ asset('images/logo.png') }}" 
    alt="{{ $alt }}" 
    {{ $attributes->merge(['class' => 'w-[180px] sm:w-[200px] md:w-[220px] h-auto mx-auto object-contain block select-none']) }}
/>
