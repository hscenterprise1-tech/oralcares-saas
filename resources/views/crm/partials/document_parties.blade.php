{{--
    The two-column "who and what" block every document opens with — the
    customer on the left, document-specific status/authorship on the right.

    Expects: $leftEyebrow, $leftTitle, $leftLines (array), $rightEyebrow,
    $rightTitle, $rightLines (array). Any line may be null/omitted by the
    caller filtering its own array first — nothing here fabricates content.
--}}
<table class="doc-card-row">
    <tr>
        <td>
            <div class="doc-card">
                <p class="doc-eyebrow">{{ $leftEyebrow }}</p>
                <p class="doc-card-title">{{ $leftTitle }}</p>
                @foreach($leftLines as $line)
                    <p class="doc-card-line">{!! $line !!}</p>
                @endforeach
            </div>
        </td>
        <td>
            <div class="doc-card">
                <p class="doc-eyebrow">{{ $rightEyebrow }}</p>
                <p class="doc-card-title">{{ $rightTitle }}</p>
                @foreach($rightLines as $line)
                    <p class="doc-card-line">{!! $line !!}</p>
                @endforeach
            </div>
        </td>
    </tr>
</table>
