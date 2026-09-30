{{--
    The commercial summary — subtotal through grand total, GST broken out
    wherever it can be, never fabricated where it can't.

    Expects: $subtotal, $discount (0 if none), $discountLabel (optional,
    e.g. "Discount · 10%"), $hasGst (bool), $gstType ('cgst_sgst'|'igst',
    only read when $hasGst), $gstRate, $cgst, $sgst, $igst, $taxableAmount,
    $flatTax (used only when !$hasGst), $grandTotal, $grandTotalLabel
    (defaults to "Grand Total").
--}}
@php
    $grandTotalLabel = $grandTotalLabel ?? 'Grand Total';
    $discountLabel = $discountLabel ?? 'Discount';
    $trimRate = fn ($rate) => rtrim(rtrim(number_format((float) $rate, 2), '0'), '.');
@endphp
<table class="doc-totals-wrap">
    <tr>
        <td>
            <div class="doc-totals">
                <table class="doc-totals-row">
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="value">₹ {{ number_format($subtotal, 2) }}</td>
                    </tr>
                    @if($discount > 0)
                        <tr class="discount">
                            <td class="label">{{ $discountLabel }}</td>
                            <td class="value">− ₹ {{ number_format($discount, 2) }}</td>
                        </tr>
                    @endif
                    @if($hasGst)
                        <tr>
                            <td class="label">Taxable Amount</td>
                            <td class="value">₹ {{ number_format($taxableAmount, 2) }}</td>
                        </tr>
                        @if($gstType === 'igst')
                            <tr class="tax">
                                <td class="label">IGST @ {{ $trimRate($gstRate) }}%</td>
                                <td class="value">+ ₹ {{ number_format($igst, 2) }}</td>
                            </tr>
                        @else
                            <tr class="tax">
                                <td class="label">CGST @ {{ $trimRate($gstRate / 2) }}%</td>
                                <td class="value">+ ₹ {{ number_format($cgst, 2) }}</td>
                            </tr>
                            <tr class="tax">
                                <td class="label">SGST @ {{ $trimRate($gstRate / 2) }}%</td>
                                <td class="value">+ ₹ {{ number_format($sgst, 2) }}</td>
                            </tr>
                        @endif
                    @else
                        {{--
                            Legacy quotation, or one whose seller/buyer state
                            couldn't resolve a split — a flat figure rather
                            than a fabricated CGST/SGST/IGST breakdown.
                        --}}
                        <tr class="tax">
                            <td class="label">Estimated Tax</td>
                            <td class="value">+ ₹ {{ number_format($flatTax, 2) }}</td>
                        </tr>
                    @endif
                </table>

                <div class="doc-grand-total">
                    <table>
                        <tr>
                            <td class="label">{{ $grandTotalLabel }}</td>
                            <td class="value">₹ {{ number_format($grandTotal, 2) }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </td>
    </tr>
</table>
<div class="doc-clear"></div>
