@props([
    'id',
    'name',
    'label',
    'type' => 'text',
    'icon' => null,
    'value' => null,
    'error' => null,
    'passwordToggle' => false,
])

<div>
    <label for="{{ $id }}" class="mb-2.5 block text-sm font-bold text-[#003DA3]">{{ $label }}</label>
    <div class="relative">
        <span class="pointer-events-none absolute left-6 top-1/2 -translate-y-1/2 text-[#5d7191]">
            @if ($icon === 'mail')
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 6h16v12H4V6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                    <path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            @elseif ($icon === 'lock')
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M7 10V8a5 5 0 0 1 10 0v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 14v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            @endif
        </span>

        <input
            id="{{ $id }}"
            name="{{ $name }}"
            @if ($passwordToggle)
                x-bind:type="showPassword ? 'text' : 'password'"
            @else
                type="{{ $type }}"
            @endif
            value="{{ $type !== 'password' ? $value : '' }}"
            {{ $attributes->class([
                'h-14 w-full rounded-lg border bg-white py-3 pl-16 pr-6 text-sm text-[#003DA3] shadow-[0_8px_22px_rgba(0,61,163,0.04)] outline-none transition duration-200 placeholder:text-[#627493] focus:border-[#009DC7] focus:ring-4 focus:ring-[#d8f3f8]',
                'border-[#cbd6e4]' => ! $error,
                'border-[#dc2626] focus:border-[#dc2626] focus:ring-[#fee2e2]' => $error,
                'pr-16' => $passwordToggle,
            ]) }}
            @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        >

        @if ($passwordToggle)
            <button type="button" class="absolute right-6 top-1/2 -translate-y-1/2 rounded-md text-[#5d7191] transition hover:text-[#009DC7] focus:outline-none focus:ring-2 focus:ring-[#009DC7] focus:ring-offset-4" x-on:click="showPassword = !showPassword" x-bind:aria-label="showPassword ? 'Hide password' : 'Show password'">
                <svg x-show="!showPassword" class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                </svg>
                <svg x-cloak x-show="showPassword" class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M3 3l18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M10.6 6.2A10.4 10.4 0 0 1 12 6c6 0 9.5 6 9.5 6a17.7 17.7 0 0 1-2.4 3.1M6.2 6.9C3.8 8.5 2.5 12 2.5 12s3.5 6 9.5 6c1.6 0 3-.4 4.2-1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        @endif
    </div>

    @if ($error)
        <p id="{{ $id }}-error" class="mt-3 text-sm font-medium text-[#dc2626]">{{ $error }}</p>
    @endif
</div>
