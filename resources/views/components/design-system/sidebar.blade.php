@props([
    'collapsible' => true
])
<aside {{ $attributes->merge(['class' => 'bg-[var(--color-bg-sidebar)] text-slate-300 flex flex-col justify-between border-r border-[#1B344E] shrink-0 transition-all duration-200 select-none']) }}
       x-data="{ expanded: true }"
       :class="expanded ? 'w-64' : 'w-20'">
    <div>
        <div class="h-16 px-5 flex items-center justify-between border-b border-[#1B344E]">
            <x-design-system.enterprise-logo size="md" x-show="expanded" />
            <x-design-system.enterprise-logo size="md" :showText="false" x-show="!expanded" style="display: none;" />
            @if($collapsible)
                <button @click="expanded = !expanded" class="text-slate-400 hover:text-white p-1 rounded-md cursor-pointer focus-ring-oralcares">
                    <svg class="h-5 w-5 stroke-current fill-none stroke-2 transition-transform duration-200" :class="expanded ? '' : 'rotate-180'" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                    </svg>
                </button>
            @endif
        </div>
        <nav class="p-4 space-y-2">
            {{ $slot }}
        </nav>
    </div>
    @if(isset($footer))
        <div class="p-4 border-t border-[#1B344E]">
            {{ $footer }}
        </div>
    @endif
</aside>
