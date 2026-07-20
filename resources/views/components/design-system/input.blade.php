@props([
    'label' => null,
    'type' => 'text',
    'id' => null,
    'name' => null,
    'error' => null,
    'required' => false,
    'placeholder' => ''
])

@php
    $id = $id ?? 'ds-input-' . \Illuminate\Support\Str::random(8);
@endphp

<div class="space-y-1.5 w-full">
    @if($label)
        <label for="{{ $id }}" class="block text-sm font-medium text-[var(--color-text-main)]">
            {{ $label }}
            @if($required)
                <span class="text-[var(--color-danger-500)]" aria-hidden="true">*</span>
            @endif
        </label>
    @endif
    <input 
        type="{{ $type }}" 
        id="{{ $id }}" 
        name="{{ $name }}" 
        placeholder="{{ $placeholder }}"
        {{ $required ? 'required' : '' }}
        {{ $attributes->merge(['class' => 'w-full px-3 py-2 bg-white dark:bg-[var(--color-neutral-800)] border border-[var(--color-border)] rounded-md text-sm text-[var(--color-text-main)] placeholder-[var(--color-neutral-400)] focus-ring transition-colors duration-150']) }}
    />
    @if($error)
        <p class="text-xs text-[var(--color-danger-500)]" id="{{ $id }}-error" role="alert">
            {{ $error }}
        </p>
    @endif
</div>
