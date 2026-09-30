@props(['mode' => 'full'])

@if($mode === 'icon')
    <img src="{{ asset('images/oralcares-logo.png') }}" {{ $attributes->merge(['alt' => 'OralCares Logo', 'class' => 'h-8 w-8 object-contain']) }} />
@else
    <div class="flex items-center space-x-2.5">
        <img src="{{ asset('images/oralcares-logo.png') }}" {{ $attributes->merge(['alt' => 'OralCares Logo', 'class' => 'h-8 w-8 object-contain']) }} />
        <span class="text-slate-900 dark:text-white text-lg font-extrabold tracking-tight font-sans">Oral<span class="text-cyan-500">Cares</span></span>
    </div>
@endif
