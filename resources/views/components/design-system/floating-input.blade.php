@props([
    'label',
    'id' => null,
    'name' => null,
    'type' => 'text',
    'value' => ''
])
@php
$id = $id ?? 'floating-input-' . \Illuminate\Support\Str::random(8);
@endphp
<div class="relative w-full">
    <input 
        type="{{ $type }}" 
        id="{{ $id }}" 
        name="{{ $name }}" 
        value="{{ $value }}"
        placeholder=" " 
        {{ $attributes->merge(['class' => 'peer block w-full px-3.5 pb-2.5 pt-6 text-sm text-[var(--color-text-main)] bg-white dark:bg-[var(--color-neutral-800)] border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-1 focus:ring-[var(--brand-teal-600)] focus:border-[var(--brand-teal-600)] transition-colors duration-150']) }} 
    />
    <label for="{{ $id }}" class="absolute text-sm text-[var(--color-text-muted)] duration-150 transform -translate-y-3.5 scale-75 top-4.5 z-10 origin-[0] left-3.5 peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-3.5 cursor-text">
        {{ $label }}
    </label>
</div>
