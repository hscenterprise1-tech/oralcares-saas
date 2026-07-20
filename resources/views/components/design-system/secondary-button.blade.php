@props([
    'type' => 'button'
])
<button type="{{ $type }}" {{ $attributes->merge(['class' => 'w-full inline-flex items-center justify-center font-bold px-4 py-3 bg-white dark:bg-[var(--color-neutral-800)] text-[var(--color-text-main)] border border-[var(--color-border)] hover:bg-[var(--neutral-50)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-teal-600)] focus:ring-offset-2 rounded-md transition duration-150 cursor-pointer disabled:opacity-50']) }}>
    {{ $slot }}
</button>
