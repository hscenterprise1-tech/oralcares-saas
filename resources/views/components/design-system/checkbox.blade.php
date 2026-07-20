@props([
    'label',
    'id' => null,
    'name' => null
])
@php
$id = $id ?? 'checkbox-' . \Illuminate\Support\Str::random(8);
@endphp
<label for="{{ $id }}" class="inline-flex items-center cursor-pointer select-none">
    <input id="{{ $id }}" name="{{ $name }}" type="checkbox" {{ $attributes->merge(['class' => 'rounded border-[var(--color-border)] text-[var(--brand-teal-600)] focus:ring-[var(--brand-teal-600)] h-4 w-4 transition duration-150']) }}>
    <span class="ms-2.5 text-xs font-semibold uppercase tracking-wider text-[var(--color-text-main)]">{{ $label }}</span>
</label>
