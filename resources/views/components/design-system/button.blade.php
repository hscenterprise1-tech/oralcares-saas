@props([
    'variant' => 'primary', // primary, secondary, danger, warning, success
    'size' => 'md', // sm, md, lg
    'type' => 'button',
    'disabled' => false
])

@php
    $baseClasses = 'inline-flex items-center justify-center font-medium rounded-md focus-ring transition-all duration-150 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed';
    
    $variants = [
        'primary' => 'bg-[var(--color-primary-500)] hover:bg-[var(--color-primary-600)] text-white shadow-sm',
        'secondary' => 'bg-white hover:bg-gray-50 text-[var(--color-neutral-700)] border border-[var(--color-border)] shadow-sm dark:bg-[var(--color-neutral-800)] dark:hover:bg-[var(--color-neutral-700)] dark:text-[var(--color-neutral-100)]',
        'danger' => 'bg-[var(--color-danger-500)] hover:bg-[var(--color-danger-700)] text-white shadow-sm',
        'warning' => 'bg-[var(--color-warning-50)] hover:bg-[var(--color-warning-700)] text-white shadow-sm',
        'success' => 'bg-[var(--color-success-500)] hover:bg-[var(--color-success-700)] text-white shadow-sm',
        'ghost' => 'text-[var(--brand-teal-600)] hover:bg-slate-100 dark:hover:bg-slate-800',
    ];

    $sizes = [
        'sm' => 'px-3 py-1.5 text-xs',
        'md' => 'px-4 py-2 text-sm',
        'lg' => 'px-5 py-2.5 text-base',
    ];

    $variantClass = $variants[$variant] ?? $variants['primary'];
    $sizeClass = $sizes[$size] ?? $sizes['md'];
@endphp

<button 
    type="{{ $type }}" 
    {{ $disabled ? 'disabled' : '' }} 
    {{ $attributes->merge(['class' => "$baseClasses $variantClass $sizeClass"]) }}
>
    {{ $slot }}
</button>
