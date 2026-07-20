@props([
    'name' => null,
    'src' => null,
    'size' => 'md', // sm, md, lg, xl
])
@php
$initials = '';
if ($name) {
    $parts = explode(' ', trim($name));
    $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

$sizes = match($size) {
    'sm' => 'w-7 h-7 text-xs',
    'lg' => 'w-11 h-11 text-base',
    'xl' => 'w-16 h-16 text-xl',
    default => 'w-9 h-9 text-sm',
};
@endphp
<div {{ $attributes->merge(['class' => "{$sizes} rounded-full bg-[var(--brand-teal-600)]/15 text-[var(--brand-teal-600)] border border-[var(--brand-teal-600)]/30 font-bold flex items-center justify-center overflow-hidden shrink-0 select-none"]) }}>
    @if($src)
        <img src="{{ $src }}" alt="{{ $name }}" class="w-full h-full object-cover" />
    @else
        <span>{{ $initials ?: 'OC' }}</span>
    @endif
</div>
