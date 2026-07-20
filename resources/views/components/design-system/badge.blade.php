@props([
    'variant' => 'neutral' // primary, success, warning, danger, neutral
])

@php
    $variants = [
        'primary' => 'bg-[var(--color-primary-50)] text-[var(--color-primary-600)] border border-[var(--color-primary-200)]',
        'success' => 'bg-[var(--color-success-50)] text-[var(--color-success-700)] border border-[var(--color-success-500)]/30',
        'warning' => 'bg-[var(--color-warning-50)] text-[var(--color-warning-700)] border border-[var(--color-warning-500)]/30',
        'danger' => 'bg-[var(--color-danger-50)] text-[var(--color-danger-700)] border border-[var(--color-danger-500)]/30',
        'neutral' => 'bg-[var(--color-neutral-50)] text-[var(--color-neutral-600)] border border-[var(--color-neutral-300)]',
    ];

    $variantClass = $variants[$variant] ?? $variants['neutral'];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold $variantClass"]) }}>
    {{ $slot }}
</span>
