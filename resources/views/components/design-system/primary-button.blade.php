@props([
    'type' => 'button'
])
<button type="{{ $type }}" {{ $attributes->merge(['class' => 'w-full inline-flex items-center justify-center font-bold px-4 py-3 bg-[var(--brand-navy-900)] text-white hover:bg-[var(--brand-navy-700)] focus:outline-none focus:ring-2 focus:ring-[var(--brand-teal-600)] focus:ring-offset-2 rounded-md transition duration-150 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed']) }}>
    <x-design-system.loading-spinner class="-ml-1 mr-3 h-5 w-5 text-white" x-show="loading" style="display: none;" />
    {{ $slot }}
</button>
