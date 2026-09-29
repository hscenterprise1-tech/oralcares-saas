{{--
    The header every OralCares document shares: brand identity on the left,
    document identity on the right, over a navy band with a teal accent —
    the same pairing as the logo mark itself.

    Deliberately typographic rather than the raster logo mark: dompdf can
    only place a PNG through the PHP GD extension, which is not guaranteed
    to be installed everywhere this renders, and a document that silently
    fails to generate is worse than one without an image. A brand wordmark
    plus a teal accent bar carries the same identity reliably in every
    environment.

    Expects: $seller (?Organization), $docType (string), $metaLines (array
    of pre-formatted "Label: value" strings, in display order).
--}}
@php
    $sellerName = $seller?->name ?? 'OralCares Enterprise';
@endphp
<div class="doc-header-band">
    <table class="doc-header-table">
        <tr>
            <td style="width:56%;">
                <span class="doc-brand-mark">O</span>
                <span class="doc-brand-name">{{ $sellerName }}</span>
                <div class="doc-brand-sub">COMMERCIAL SALES &amp; LICENSING DIVISION</div>
                @if($seller)
                    <div class="doc-brand-meta">
                        @if($seller->address || $seller->city || $seller->state)
                            {{ collect([$seller->address, $seller->city, $seller->state])->filter()->implode(', ') }}<br>
                        @endif
                        @if($seller->gst)
                            GSTIN: {{ $seller->gst }}
                        @endif
                    </div>
                @endif
            </td>
            <td style="width:44%;">
                <div class="doc-doctype">{{ $docType }}</div>
                <div class="doc-doc-meta">
                    @foreach($metaLines as $line)
                        {!! $line !!}<br>
                    @endforeach
                </div>
            </td>
        </tr>
    </table>
</div>
<div class="doc-header-accent"></div>
