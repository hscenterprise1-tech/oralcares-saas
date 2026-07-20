@props([
    'variant' => 'info', // info, success, warning, danger
    'title' => null
])
@php
$styles = match ($variant) {
    'success' => 'bg-emerald-50 dark:bg-emerald-950/20 border-l-4 border-[var(--color-success)] text-[var(--color-success)]',
    'warning' => 'bg-amber-50 dark:bg-amber-950/20 border-l-4 border-[var(--color-warning)] text-[var(--color-warning)]',
    'danger' => 'bg-rose-50 dark:bg-rose-950/20 border-l-4 border-[var(--color-danger)] text-[var(--color-danger)]',
    default => 'bg-sky-50 dark:bg-sky-950/20 border-l-4 border-[var(--brand-teal-600)] text-[var(--brand-teal-600)]',
};
@endphp
<div {{ $attributes->merge(['class' => "p-4 rounded-r-md text-xs font-semibold {$styles}"]) }} role="alert">
    @if($title)
        <p class="font-bold mb-1">{{ $title }}</p>
    @endif
    <p>{{ $slot }}</p>
</div>
