@props([
    'items' => [],
    'active' => null
])
<div class="border-b border-[var(--color-border)]" x-data="{ activeTab: @js($active ?? ($items[0]['id'] ?? '')) }">
    <nav class="-mb-px flex space-x-8" aria-label="Tabs">
        @foreach($items as $tab)
            <button 
                type="button"
                @click="activeTab = '{{ $tab['id'] }}'"
                :class="activeTab === '{{ $tab['id'] }}' 
                    ? 'border-[var(--brand-teal-600)] text-[var(--brand-teal-600)] font-bold' 
                    : 'border-transparent text-[var(--color-text-muted)] hover:text-[var(--color-text-main)] hover:border-[var(--color-border-hover)] font-medium'"
                class="whitespace-nowrap py-3 px-1 border-b-2 text-sm transition-colors duration-150 cursor-pointer focus-ring-oralcares rounded-t-sm"
            >
                {{ $tab['label'] }}
            </button>
        @endforeach
    </nav>
</div>
