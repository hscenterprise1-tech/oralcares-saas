@props([
    'placeholder' => 'Search...',
    'id' => null,
    'name' => 'search'
])
@php
$id = $id ?? 'search-input-' . \Illuminate\Support\Str::random(8);
@endphp
<div class="relative w-full">
    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-[var(--color-text-muted)]">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
    </div>
    <input 
        type="search" 
        id="{{ $id }}" 
        name="{{ $name }}" 
        placeholder="{{ $placeholder }}"
        {{ $attributes->merge(['class' => 'w-full pl-10 pr-4 py-2 bg-white dark:bg-[var(--neutral-900)] border border-[var(--color-border)] rounded-[var(--radius-md)] text-sm text-[var(--color-text-main)] placeholder-[var(--color-text-muted)] focus-ring-oralcares transition-colors duration-150']) }} 
    />
</div>
