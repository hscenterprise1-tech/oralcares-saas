{{--
    Structured commercial terms — each section only appears when the
    quotation actually carries one. Content comes entirely from the
    quotation's own columns (defaulted at creation from
    `config('commercial.default_terms')`, never hardcoded here.

    Expects: $quotation (a CompanyQuotation-shaped object exposing
    valid_until, payment_terms, implementation_terms, support_terms,
    cancellation_terms, terms).
--}}
@php
    $rows = array_filter([
        'Validity' => $quotation->valid_until ? \Illuminate\Support\Carbon::parse($quotation->valid_until)->format('d M Y') : null,
        'Payment Terms' => $quotation->payment_terms ?? null,
        'Implementation &amp; Setup' => $quotation->implementation_terms ?? null,
        'Support / AMC' => $quotation->support_terms ?? null,
        'Cancellation &amp; Refund' => $quotation->cancellation_terms ?? null,
        'Terms &amp; Conditions' => $quotation->terms ?? null,
    ]);
@endphp
@if(count($rows) > 0)
    <div class="doc-section">
        <p class="doc-section-title">Commercial Terms</p>
        <table class="doc-terms-grid">
            @foreach($rows as $label => $value)
                <tr>
                    <td class="doc-term-label">{!! $label !!}</td>
                    <td class="doc-term-value">{!! nl2br(e($value)) !!}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif
