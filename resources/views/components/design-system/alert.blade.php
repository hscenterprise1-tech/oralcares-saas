@props([
    'variant' => 'info', // info, success, warning, danger
    'title' => null
])

@php
    $variants = [
        'info' => 'bg-sky-50 text-[var(--color-primary-800)] border-l-4 border-[var(--color-primary-500)] dark:bg-sky-950/20 dark:text-sky-300',
        'success' => 'bg-emerald-50 text-[var(--color-success-700)] border-l-4 border-[var(--color-success-500)] dark:bg-emerald-950/20 dark:text-emerald-300',
        'warning' => 'bg-amber-50 text-[var(--color-warning-700)] border-l-4 border-[var(--color-warning-500)] dark:bg-amber-950/20 dark:text-amber-300',
        'danger' => 'bg-rose-50 text-[var(--color-danger-700)] border-l-4 border-[var(--color-danger-500)] dark:bg-rose-950/20 dark:text-rose-300',
    ];

    $variantClass = $variants[$variant] ?? $variants['info'];
@endphp

<div {{ $attributes->merge(['class' => "p-4 rounded-r-md $variantClass", 'role' => 'alert']) }}>
    <div class="flex">
        <div class="flex-1">
            @if($title)
                <h4 class="text-sm font-semibold mb-1">{{ $title }}</h4>
            @endif
            <div class="text-sm">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
