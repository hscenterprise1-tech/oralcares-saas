@props([
    'label' => null,
    'checked' => false,
    'id' => null,
    'name' => null
])
@php
$id = $id ?? 'toggle-' . \Illuminate\Support\Str::random(8);
@endphp
<div class="flex items-center" x-data="{ enabled: @js($checked) }">
    <button 
        type="button" 
        id="{{ $id }}"
        @click="enabled = !enabled" 
        :class="enabled ? 'bg-[var(--brand-teal-600)]' : 'bg-[var(--neutral-200)] dark:bg-[var(--neutral-700)]'"
        class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus-ring-oralcares"
        role="switch" 
        :aria-checked="enabled.toString()"
    >
        <span 
            :class="enabled ? 'translate-x-5' : 'translate-x-0'"
            class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
        ></span>
    </button>
    <input type="checkbox" :checked="enabled" name="{{ $name }}" class="hidden" />
    @if($label)
        <label for="{{ $id }}" @click="enabled = !enabled" class="ml-3 text-sm font-medium text-[var(--color-text-main)] cursor-pointer select-none">
            {{ $label }}
        </label>
    @endif
</div>
