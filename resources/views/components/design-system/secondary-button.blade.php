@props([
    'type' => 'button'
])
<button type="{{ $type }}" {{ $attributes->merge(['class' => 'w-full inline-flex items-center justify-center font-bold px-5 py-3.5 bg-white dark:bg-[var(--neutral-900)] text-[var(--color-text-main)] border border-[var(--color-border)] hover:border-[var(--brand-teal-600)] hover:bg-[var(--neutral-25)] focus-ring-oralcares rounded-[var(--radius-md)] transition-all duration-180 cursor-pointer disabled:opacity-50 text-sm']) }}>
    {{ $slot }}
</button>
