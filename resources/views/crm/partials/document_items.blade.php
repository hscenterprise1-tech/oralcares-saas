{{--
    The line-item table — package base, additional users, add-ons and custom
    lines all render the same way; `item_type` only earns a small tag next to
    the title so a reader can see what generated the line, never a different
    row style.

    Expects: $items (an iterable of objects/arrays each carrying title,
    description, quantity, unit_price, discount, total, and optionally
    item_type).
--}}
@php
    $itemTypeLabels = [
        'package_base' => 'Package',
        'additional_users' => 'Additional Users',
        'addon' => 'Add-on',
    ];
@endphp
<table class="doc-items">
    <thead>
        <tr>
            <th>Item / Description</th>
            <th class="num">Qty</th>
            <th class="num">Unit Price (₹)</th>
            <th class="num">Discount (₹)</th>
            <th class="num">Amount (₹)</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $item)
            @php
                $itemType = is_array($item) ? ($item['item_type'] ?? null) : ($item->item_type ?? null);
                $tagLabel = $itemTypeLabels[$itemType] ?? null;
                $title = is_array($item) ? $item['title'] : $item->title;
                $description = is_array($item) ? ($item['description'] ?? null) : $item->description;
                $quantity = is_array($item) ? $item['quantity'] : $item->quantity;
                $unitPrice = is_array($item) ? $item['unit_price'] : $item->unit_price;
                $discount = is_array($item) ? ($item['discount'] ?? 0) : $item->discount;
                $total = is_array($item) ? $item['total'] : $item->total;
            @endphp
            <tr>
                <td>
                    <span class="doc-item-title">{{ $title }}</span>
                    @if($tagLabel)
                        <span class="doc-item-tag">{{ $tagLabel }}</span>
                    @endif
                    @if($description)
                        <div class="doc-item-desc">{{ $description }}</div>
                    @endif
                </td>
                <td class="num">{{ $quantity }}</td>
                <td class="num">{{ number_format($unitPrice, 2) }}</td>
                <td class="num">{{ $discount > 0 ? number_format($discount, 2) : '—' }}</td>
                <td class="num">{{ number_format($total, 2) }}</td>
            </tr>
        @empty
            <tr class="doc-empty-row">
                <td colspan="5">No line items recorded on this quotation.</td>
            </tr>
        @endforelse
    </tbody>
</table>
