@props([
    'paginator' => null
])
<div {{ $attributes->merge(['class' => 'flex items-center justify-between border-t border-[var(--color-border)] px-4 py-3 bg-[var(--color-bg-card)] rounded-b-[var(--radius-lg)]']) }}>
    <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
        <div>
            <p class="text-xs text-[var(--color-text-muted)]">
                Showing <span class="font-bold text-[var(--color-text-main)]">1</span> to <span class="font-bold text-[var(--color-text-main)]">10</span> of <span class="font-bold text-[var(--color-text-main)]">42</span> results
            </p>
        </div>
        <div>
            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                <button type="button" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-[var(--color-border)] bg-white dark:bg-[var(--neutral-900)] text-xs font-medium text-[var(--color-text-muted)] hover:bg-[var(--neutral-50)] cursor-pointer">
                    Previous
                </button>
                <button type="button" class="relative inline-flex items-center px-3.5 py-2 border border-[var(--brand-teal-600)] bg-[var(--brand-teal-600)]/10 text-xs font-bold text-[var(--brand-teal-600)] cursor-pointer">
                    1
                </button>
                <button type="button" class="relative inline-flex items-center px-3.5 py-2 border border-[var(--color-border)] bg-white dark:bg-[var(--neutral-900)] text-xs font-medium text-[var(--color-text-main)] hover:bg-[var(--neutral-50)] cursor-pointer">
                    2
                </button>
                <button type="button" class="relative inline-flex items-center px-3.5 py-2 border border-[var(--color-border)] bg-white dark:bg-[var(--neutral-900)] text-xs font-medium text-[var(--color-text-main)] hover:bg-[var(--neutral-50)] cursor-pointer">
                    3
                </button>
                <button type="button" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-[var(--color-border)] bg-white dark:bg-[var(--neutral-900)] text-xs font-medium text-[var(--color-text-muted)] hover:bg-[var(--neutral-50)] cursor-pointer">
                    Next
                </button>
            </nav>
        </div>
    </div>
</div>
