@php
    /**
     * The printable patient summary (Patient 360 → Print). Everything here was
     * built by `Patient360Summary` for the member who printed it: what they may
     * not see on the page is not on the paper either.
     */
    $money = fn ($value) => '₹'.number_format((float) $value, 2);
    $date = fn (?string $value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d M Y') : null;
    $ageSex = collect([$patient['age'] !== null ? $patient['age'].' y' : null, $patient['gender'] ? ucfirst($patient['gender']) : null, $patient['blood_group'] ? 'Blood group '.$patient['blood_group'] : null])->filter()->implode(' · ');
    $care = $overview['current']['active_care'] ?? [];
    $next = $overview['next'] ?? [];
    $recent = $overview['recent'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient summary · {{ $patient['name'] }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #101828; font-size: 11.5px; line-height: 1.45; margin: 0; background: #f2f4f7; }
        .page { background: #fff; max-width: 190mm; margin: 16px auto; padding: 20px 22px; }
        .bar { text-align: center; padding: 12px; }
        .bar button { font: 600 13px Helvetica, Arial, sans-serif; padding: 10px 18px; border-radius: 999px; border: 0; background: #101828; color: #fff; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        .clinic { font-size: 16px; font-weight: 700; }
        .muted { color: #475467; }
        .right { text-align: right; }
        .rule { border-top: 2px solid #101828; margin: 10px 0 10px; }
        .name { font-size: 20px; font-weight: 700; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.07em; color: #475467; margin: 16px 0 6px; border-bottom: 1px solid #d0d5dd; padding-bottom: 4px; }
        .label { color: #475467; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; }
        .facts td { padding: 4px 8px 4px 0; width: 25%; }
        .chip { display: inline-block; padding: 2px 8px; border-radius: 999px; border: 1px solid #d0d5dd; margin: 0 4px 4px 0; }
        .alert { border-color: #f04438; color: #b42318; font-weight: 700; }
        ul { margin: 0; padding-left: 16px; }
        li { margin: 2px 0; }
        .stamp { margin-top: 18px; font-size: 9.5px; color: #475467; }
        @media print { body { background: #fff; } .page { margin: 0; max-width: none; padding: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
<div class="bar no-print"><button type="button" onclick="window.print()">Print summary</button></div>

<div class="page">
    <table>
        <tr>
            <td>
                <div class="clinic">{{ $clinic->name }}</div>
                <div class="muted">Patient summary</div>
            </td>
            <td class="right muted">Printed {{ $printedAt->format('d M Y, h:i A') }}<br>by {{ $printedBy }}</td>
        </tr>
    </table>
    <div class="rule"></div>

    <div class="name">{{ $patient['name'] }}</div>
    <div class="muted">{{ collect([$patient['uhid'] ? 'UHID '.$patient['uhid'] : null, $ageSex ?: null])->filter()->implode(' · ') }}</div>

    <table class="facts" style="margin-top:10px">
        <tr>
            <td><div class="label">Mobile</div>{{ $patient['phone'] ?? ($patient['phone_last_digits'] ? '•••••• '.$patient['phone_last_digits'] : '—') }}</td>
            <td><div class="label">Assigned doctor</div>{{ $patient['practitioner'] ?? 'Not assigned' }}</td>
            <td><div class="label">Registered</div>{{ $date($patient['registered_on']) ?? '—' }}@if ($patient['registered_branch']) <span class="muted">· {{ $patient['registered_branch'] }}</span>@endif</td>
            <td><div class="label">Last visit</div>{{ $date($patient['last_visit_on']) ?? 'No visits yet' }}@if ($patient['branch']) <span class="muted">· {{ $patient['branch'] }}</span>@endif</td>
        </tr>
        @if ($profile['address'] || $profile['emergency_contact'])
            <tr>
                <td colspan="2"><div class="label">Address</div>{{ $profile['address'] ?? '—' }}</td>
                <td colspan="2"><div class="label">Emergency contact</div>
                    @if ($profile['emergency_contact'])
                        {{ $profile['emergency_contact']['name'] ?? 'Not named' }}@if ($profile['emergency_contact']['phone'] || $profile['emergency_contact']['phone_last_digits']) · {{ $profile['emergency_contact']['phone'] ?? '•••••• '.$profile['emergency_contact']['phone_last_digits'] }}@endif
                    @else — @endif
                </td>
            </tr>
        @endif
    </table>

    <h2>Safety</h2>
    <div><span class="label">Allergies</span>
        @forelse ($context['allergies'] as $allergy)<span class="chip alert">{{ $allergy }}</span>@empty <span class="muted">None recorded</span>@endforelse
    </div>
    @if (count($context['alerts']) > 0)
        <div style="margin-top:6px"><span class="label">Alerts</span>
            @foreach ($context['alerts'] as $alert)<span class="chip">{{ $alert['text'] }}</span>@endforeach
        </div>
    @endif

    @if ($context['clinical'])
        <h2>Conditions and medicines</h2>
        @php($conditions = collect($context['conditions']['diagnosed'])->pluck('description')->merge($context['conditions']['reported']))
        <div><span class="label">Conditions</span> {{ $conditions->isEmpty() ? 'None recorded' : $conditions->implode(', ') }}</div>
        @php($medicines = collect($context['medications']['prescribed'])->map(fn ($m) => trim($m['name'].' '.collect([$m['dosage'], $m['frequency'], $m['duration']])->filter()->implode(' · ')))->merge($context['medications']['reported']))
        <div style="margin-top:4px"><span class="label">Medicines</span> {{ $medicines->isEmpty() ? 'None recorded' : $medicines->implode('; ') }}</div>
        @if ($context['medical_notes'])<div style="margin-top:4px"><span class="label">Medical notes</span> {{ $context['medical_notes'] }}</div>@endif
    @endif

    <h2>Care under way</h2>
    @forelse ($care as $item)
        <div>{{ $item['title'] }}@if ($item['progress']['total']) <span class="muted">· {{ $item['progress']['done'] }}/{{ $item['progress']['total'] }} {{ $item['progress']['unit'] }}</span>@endif @if ($item['practitioner']) <span class="muted">· {{ $item['practitioner']['name'] }}</span>@endif</div>
    @empty
        <div class="muted">No treatment under way.</div>
    @endforelse

    <h2>Coming up</h2>
    @if ($next['appointment'] ?? null)
        <div>Next appointment: {{ $next['appointment']['date_label'] }} · {{ $next['appointment']['time_label'] }}@if ($next['appointment']['practitioner']) · {{ $next['appointment']['practitioner'] }}@endif</div>
    @endif
    @if ($next['follow_up'] ?? null)
        <div>Follow-up due {{ $date($next['follow_up']['due_on']) }}@if ($next['follow_up']['reason']) · {{ $next['follow_up']['reason'] }}@endif</div>
    @endif
    @if (! ($next['appointment'] ?? null) && ! ($next['follow_up'] ?? null))<div class="muted">Nothing booked or due.</div>@endif

    @if ($context['clinical'] && ($recent['consultation'] ?? null))
        <h2>Last consultation</h2>
        <div>{{ $date($recent['consultation']['date']) }}@if ($recent['consultation']['practitioner']) · {{ $recent['consultation']['practitioner'] }}@endif</div>
        @if ($recent['consultation']['complaint'])<div class="muted">Complaint: {{ $recent['consultation']['complaint'] }}</div>@endif
        @if ($recent['consultation']['diagnosis'])<div class="muted">Diagnosis: {{ $recent['consultation']['diagnosis'] }}</div>@endif
    @endif

    @if ($overview['summary']['finance'] ?? null)
        <h2>Billing</h2>
        <div>Billed {{ $money($overview['summary']['finance']['billed']) }} · Paid {{ $money($overview['summary']['finance']['paid']) }} · <strong>Outstanding {{ $money($overview['summary']['finance']['outstanding']) }}</strong></div>
    @endif

    <div class="stamp">This summary shows only what {{ $printedBy }} may see in Patient 360. It is not a clinical report or a bill.</div>
</div>
</body>
</html>
