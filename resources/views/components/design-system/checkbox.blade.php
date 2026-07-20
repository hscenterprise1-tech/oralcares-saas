@props([
    'label' => null,
    'id' => null,
    'name' => null,
    'checked' => false
])

@php
    $id = $id ?? 'ds-checkbox-' . \Illuminate\Support\Str::random(8);
@endphp

<div class="flex items-center space-x-2">
    <input 
        type="checkbox" 
        id="{{ $id }}" 
        name="{{ $name }}" 
        {{ $checked ? 'checked' : '' }}
        {{ $attributes->merge(['class' => 'h-4 w-4 text-[var(--color-primary-500)] focus:ring-[var(--color-primary-500)] border-[var(--color-border)] rounded transition-colors duration-150']) }}
    />
    @if($label)
        <label for="{{ $id }}" class="text-sm font-medium text-[var(--color-text-main)] select-none">
            {{ $label }}
        </label>
    @endif
</div>
