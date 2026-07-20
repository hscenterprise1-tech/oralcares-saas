<div {{ $attributes->merge(['class' => 'w-full max-w-[520px] bg-[var(--color-bg-card)] border border-[var(--color-border)] p-10 shadow-[var(--shadow-elevated)] rounded-[var(--radius-xl)] transition-colors duration-200 hover:border-[var(--color-border-hover)]']) }}>
    {{ $slot }}
</div>
