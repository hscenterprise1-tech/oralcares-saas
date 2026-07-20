@props([
    'variant' => 'info', // info, success, warning, danger
    'title' => null
])
@php
$styles = match ($variant) {
    'success' => 'bg-emerald-50 dark:bg-emerald-950/30 border-l-3 border-[var(--color-success)] text-emerald-800 dark:text-emerald-300',
    'warning' => 'bg-amber-50 dark:bg-amber-950/30 border-l-3 border-[var(--color-warning)] text-amber-800 dark:text-amber-300',
    'danger' => 'bg-rose-50 dark:bg-rose-950/30 border-l-3 border-[var(--color-danger)] text-rose-800 dark:text-rose-300',
    default => 'bg-cyan-50 dark:bg-cyan-950/30 border-l-3 border-[var(--brand-teal-600)] text-cyan-900 dark:text-cyan-200',
};
@endphp
<div {{ $attributes->merge(['class' => "p-4 rounded-r-[var(--radius-md)] text-xs font-medium flex items-start space-x-3 {$styles}"]) }} role="alert">
    <div class="shrink-0 mt-0.5">
        @if($variant === 'danger')
            <svg class="w-4 h-4 text-[var(--color-danger)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        @elseif($variant === 'success')
            <svg class="w-4 h-4 text-[var(--color-success)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        @else
            <svg class="w-4 h-4 text-[var(--brand-teal-600)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        @endif
    </div>
    <div class="flex-1">
        @if($title)
            <p class="font-bold mb-0.5 text-sm">{{ $title }}</p>
        @endif
        <p>{{ $slot }}</p>
    </div>
</div>
