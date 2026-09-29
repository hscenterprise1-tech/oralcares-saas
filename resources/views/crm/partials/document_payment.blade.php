{{--
    Payment instructions — only rendered when the seller actually has any
    configured, never a placeholder invented to fill the space.

    Expects: $seller (?Organization).
--}}
@if($seller && ($seller->upi_id || $seller->bank_account_number))
    <div class="doc-section">
        <p class="doc-section-title">Payment Instructions</p>
        <div class="doc-payment-card">
            @if($seller->upi_id)
                <div class="row"><strong>UPI ID:</strong> {{ $seller->upi_id }}</div>
            @endif
            @if($seller->bank_account_number)
                <div class="row"><strong>Account Name:</strong> {{ $seller->bank_account_name ?? $seller->name }}</div>
                <div class="row"><strong>Account Number:</strong> {{ $seller->bank_account_number }}</div>
                @if($seller->bank_ifsc)
                    <div class="row"><strong>IFSC:</strong> {{ $seller->bank_ifsc }}</div>
                @endif
                @if($seller->bank_name)
                    <div class="row"><strong>Bank:</strong> {{ $seller->bank_name }}</div>
                @endif
            @endif
        </div>
    </div>
@endif
