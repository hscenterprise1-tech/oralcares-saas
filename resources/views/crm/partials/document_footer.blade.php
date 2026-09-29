{{--
    Expects: $seller (?Organization), $tagline (string).
--}}
<div class="doc-footer">
    <strong>{{ $seller?->name ?? 'OralCares Enterprise' }}</strong> &bull; {{ $tagline }}
</div>
