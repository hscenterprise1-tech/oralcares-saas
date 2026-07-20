@props([
    'label',
    'id' => null,
    'name' => null,
    'type' => 'text',
    'value' => '',
    'error' => null
])
@php
$id = $id ?? 'floating-input-' . \Illuminate\Support\Str::random(8);
@endphp
<div class="space-y-1.5 w-full">
    <div class="relative w-full">
        <input 
            type="{{ $type }}" 
            id="{{ $id }}" 
            name="{{ $name }}" 
            value="{{ $value }}"
            placeholder=" " 
            {{ $attributes->merge(['class' => 'peer block w-full px-4 pb-2.5 pt-6 text-sm text-[var(--color-text-main)] bg-white dark:bg-[var(--neutral-900)] border border-[var(--color-border)] rounded-[var(--radius-md)] focus-ring-oralcares transition-colors duration-150']) }} 
        />
        <label for="{{ $id }}" class="absolute text-sm text-[var(--color-text-muted)] duration-150 transform -translate-y-3.5 scale-75 top-4.5 z-10 origin-[0] left-4 peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-3.5 cursor-text select-none">
            {{ $label }}
        </label>
    </div>
    @if($error)
        <p class="text-xs font-semibold text-[var(--color-danger)] mt-1" id="{{ $id }}-error" role="alert">
            {{ $error }}
        </p>
    @endif
</div>
