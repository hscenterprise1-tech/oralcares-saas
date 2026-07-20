@props([
    'value' => 0,
    'size' => 'md',
    'color' => 'teal'
])
@php
$height = match($size) {
    'sm' => 'h-1.5',
    'lg' => 'h-3.5',
    default => 'h-2.5',
};

$bg = match($color) {
    'navy' => 'bg-[var(--brand-navy-900)]',
    'success' => 'bg-[var(--color-success)]',
    'danger' => 'bg-[var(--color-danger)]',
    default => 'bg-[var(--brand-teal-600)]',
};
@endphp
<div {{ $attributes->merge(['class' => "w-full bg-[var(--neutral-100)] dark:bg-[var(--neutral-800)] rounded-full overflow-hidden {$height}"]) }} role="progressbar" aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100">
    <div class="{{ $height }} {{ $bg }} rounded-full transition-all duration-300 ease-out" style="width: {{ min(100, max(0, $value)) }}%;"></div>
</div>
