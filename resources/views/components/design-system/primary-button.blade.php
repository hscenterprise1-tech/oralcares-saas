@props([
    'type' => 'button'
])
<button type="{{ $type }}" {{ $attributes->merge(['class' => 'btn-primary-oralcares w-full inline-flex items-center justify-center font-bold px-5 py-3.5 text-white focus-ring-oralcares rounded-[var(--radius-md)] cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed text-sm']) }}>
    <x-design-system.loading-spinner class="-ml-1 mr-3 h-5 w-5 text-white" x-show="loading" style="display: none;" />
    {{ $slot }}
</button>
