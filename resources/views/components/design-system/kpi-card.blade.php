@props([
    'title',
    'value',
    'change' => null,
    'changeType' => 'positive', // positive, negative, neutral
    'subtitle' => null
])
<div {{ $attributes->merge(['class' => 'bg-[var(--color-bg-card)] border border-[var(--color-border)] p-6 rounded-[var(--radius-lg)] shadow-[var(--shadow-card)] flex flex-col justify-between hover:border-[var(--color-border-hover)] transition-colors duration-180']) }}>
    <div class="flex items-center justify-between">
        <span class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wider">{{ $title }}</span>
        @if($change)
            @php
            $badgeStyles = match($changeType) {
                'positive' => 'bg-[var(--color-success-bg)] text-[var(--color-success)]',
                'negative' => 'bg-[var(--color-danger-bg)] text-[var(--color-danger)]',
                default => 'bg-[var(--neutral-50)] text-[var(--neutral-700)]',
            };
            @endphp
            <span class="px-2 py-0.5 text-xs font-bold rounded-full {{ $badgeStyles }} font-mono">
                {{ $change }}
            </span>
        @endif
    </div>
    <div class="mt-4">
        <div class="text-3xl font-extrabold text-[var(--color-text-main)] tracking-tight font-mono">{{ $value }}</div>
        @if($subtitle)
            <p class="text-xs text-[var(--color-text-muted)] mt-1">{{ $subtitle }}</p>
        @endif
    </div>
</div>
