<header {{ $attributes->merge(['class' => 'sticky top-0 z-[1100] h-16 bg-[var(--color-bg-header)] border-b border-[var(--color-border)] px-6 flex items-center justify-between shrink-0 shadow-[var(--shadow-subtle)]']) }}>
    <div class="flex items-center space-x-4">
        {{ $left ?? '' }}
    </div>
    <div class="flex items-center space-x-4">
        {{ $right ?? $slot }}
    </div>
</header>
