@props([
    'items' => []
])
<nav {{ $attributes->merge(['class' => 'flex text-sm font-medium text-[var(--color-text-muted)]']) }} aria-label="Breadcrumb">
    <ol class="inline-flex items-center space-x-1.5 md:space-x-2">
        @foreach($items as $index => $item)
            <li class="inline-flex items-center">
                @if($index > 0)
                    <svg class="w-3.5 h-3.5 text-[var(--neutral-400)] mx-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                @endif

                @if(!empty($item['url']) && $index < count($items) - 1)
                    <a href="{{ $item['url'] }}" class="hover:text-[var(--color-text-main)] transition-colors duration-150">
                        {{ $item['label'] }}
                    </a>
                @else
                    <span class="text-[var(--color-text-main)] font-semibold">{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
