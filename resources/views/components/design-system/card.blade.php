@props([
    'title' => null,
    'footer' => null
])

<div {{ $attributes->merge(['class' => 'bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-lg shadow-sm overflow-hidden flex flex-col']) }}>
    @if($title)
        <div class="px-6 py-4 border-b border-[var(--color-border)] bg-gray-50/50 dark:bg-[var(--color-neutral-800)]/50">
            <h3 class="text-base font-semibold text-[var(--color-text-main)]">{{ $title }}</h3>
        </div>
    @endif
    
    <div class="p-6 flex-1 text-sm text-[var(--color-text-main)]">
        {{ $slot }}
    </div>

    @if($footer)
        <div class="px-6 py-4 border-t border-[var(--color-border)] bg-gray-50/50 dark:bg-[var(--color-neutral-800)]/50">
            {{ $footer }}
        </div>
    @endif
</div>
