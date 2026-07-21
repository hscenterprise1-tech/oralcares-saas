@props([
    'label' => null,
    'type' => 'text',
    'id' => null,
    'name' => null,
    'error' => null,
    'required' => false,
    'placeholder' => '',
    'icon' => null
])
@php
$id = $id ?? 'input-' . \Illuminate\Support\Str::random(8);
@endphp
<div class="space-y-2 w-full">
    @if($label)
        <label for="{{ $id }}" class="block text-xs font-semibold uppercase tracking-wider text-[#475569]">
            {{ $label }}
            @if($required)
                <span class="text-[#EF4444]" aria-hidden="true">*</span>
            @endif
        </label>
    @endif
    <div class="relative w-full">
        @if($icon)
            <div class="absolute left-4 top-1/2 -translate-y-1/2 flex items-center pointer-events-none text-[#94A3B8]">
                {{ $icon }}
            </div>
        @endif
        <input 
            type="{{ $type }}" 
            id="{{ $id }}" 
            name="{{ $name }}" 
            placeholder="{{ $placeholder }}"
            {{ $required ? 'required' : '' }}
            {{ $attributes->merge(['class' => 'w-full h-[50px] ' . ($icon ? 'pl-11' : 'px-4') . ' pr-4 bg-white border border-[var(--color-border)] hover:border-[var(--color-border-hover)] rounded-xl text-sm font-normal text-[#0F172A] placeholder-[#94A3B8] focus-ring-enterprise transition-all duration-150']) }}
        />
    </div>
    @if($error)
        <p class="text-xs font-medium text-[#EF4444] mt-1.5" id="{{ $id }}-error" role="alert">
            {{ $error }}
        </p>
    @endif
</div>
