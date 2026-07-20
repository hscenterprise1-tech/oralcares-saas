@props([
    'title',
    'description' => null,
    'icon' => null
])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center p-12 text-center bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-[var(--radius-lg)]']) }}>
    <div class="p-4 bg-[var(--neutral-50)] dark:bg-[var(--neutral-800)] text-[var(--brand-teal-600)] rounded-full mb-4">
        @if($icon)
            {{ $icon }}
        @else
            <svg class="w-8 h-8 stroke-current fill-none stroke-[1.5]" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0l-3-3m3 3l3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
            </svg>
        @endif
    </div>
    <h3 class="text-base font-bold text-[var(--color-text-main)]">{{ $title }}</h3>
    @if($description)
        <p class="text-xs text-[var(--color-text-muted)] max-w-sm mt-1.5 leading-relaxed">{{ $description }}</p>
    @endif
    @if($slot->isNotEmpty())
        <div class="mt-6">
            {{ $slot }}
        </div>
    @endif
</div>
