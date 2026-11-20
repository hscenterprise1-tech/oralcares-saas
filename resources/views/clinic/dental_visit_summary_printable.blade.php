@php
    /**
     * What the patient takes home after a dental visit: why they came, what was found, what was done today,
     * the advice, and the visits already booked. Built by `ConsultationController::dentalSummary` from the
     * visit's own records; printing it records nothing.
     *
     * @var \App\Models\Clinic $clinic
     * @var \App\Models\Branch|null $branch
     * @var \App\Models\Patient $patient
     * @var \App\Models\ClinicalEncounter $encounter
     * @var string|null $dentist
     * @var list<string> $diagnoses
     * @var list<array{name: string, tooth: int|string|null}> $done
     * @var list<array{when: string, with: string|null}> $next
     */
    $age = $patient->date_of_birth ? (int) $patient->date_of_birth->diffInYears(now()) : $patient->age;
    $branchAddress = $branch ? collect([$branch->address_line1, $branch->address_line2, $branch->city, $branch->state, $branch->pincode])->filter()->implode(', ') : null;
    $name = $clinic->display_name ?: $clinic->name;
    $seen = \App\Support\ClinicClock::fromUtc($encounter->finalized_at ?? $encounter->started_at);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visit summary · {{ $patient->full_name }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #101828; font-size: 12px; line-height: 1.5; margin: 0; background: #f2f4f7; }
        .page { background: #fff; max-width: 190mm; margin: 16px auto; padding: 20px 24px; }
        .bar { text-align: center; padding: 12px; }
        .bar button { font: 600 13px Helvetica, Arial, sans-serif; padding: 10px 18px; border-radius: 999px; border: 0; background: #101828; color: #fff; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        .clinic { font-size: 17px; font-weight: 700; }
        .muted { color: #475467; }
        .right { text-align: right; }
        .rule { border-top: 2px solid #101828; margin: 10px 0 12px; }
        .label { color: #475467; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; }
        h1 { font-size: 15px; margin: 0 0 8px; text-transform: uppercase; letter-spacing: 0.06em; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.07em; color: #475467; margin: 16px 0 6px; border-bottom: 1px solid #d0d5dd; padding-bottom: 4px; }
        ul { margin: 0; padding-left: 18px; }
        li { margin: 3px 0; }
        p { margin: 0 0 6px; white-space: pre-line; }
        .sign { margin-top: 36px; }
        .line { border-top: 1px solid #101828; padding-top: 4px; font-size: 10.5px; color: #475467; width: 60mm; margin-left: auto; text-align: center; }
        .foot { margin-top: 18px; font-size: 10px; color: #667085; }
        @media print { body { background: #fff; } .page { margin: 0; max-width: none; padding: 0; } .bar { display: none; } }
    </style>
</head>
<body>
    <div class="bar"><button type="button" onclick="window.print()">Print</button></div>
    <div class="page">
        <table>
            <tr>
                <td>
                    <div class="clinic">{{ $name }}</div>
                    @if ($branchAddress)<div class="muted">{{ $branchAddress }}</div>@endif
                    @if ($branch?->phone)<div class="muted">{{ $branch->phone }}</div>@endif
                </td>
                <td class="right muted">Visit on {{ $seen->format('d M Y') }}<br>{{ $encounter->encounter_number }}</td>
            </tr>
        </table>
        <div class="rule"></div>

        <h1>Dental visit summary</h1>
        <table>
            <tr>
                <td><span class="label">Patient</span><br><strong>{{ $patient->full_name }}</strong></td>
                <td><span class="label">UHID</span><br>{{ $patient->uhid ?? '—' }}</td>
                <td><span class="label">Age / sex</span><br>{{ collect([$age !== null ? $age.' y' : null, $patient->gender ? ucfirst($patient->gender) : null])->filter()->implode(' · ') ?: '—' }}</td>
                <td><span class="label">Dentist</span><br>{{ $dentist ?? '—' }}</td>
            </tr>
        </table>

        @if (filled($encounter->chief_complaint))
            <h2>Why you came</h2>
            <p>{{ $encounter->chief_complaint }}</p>
        @endif

        @if (count($diagnoses) > 0 || filled($encounter->examination))
            <h2>What was found</h2>
            @if (filled($encounter->examination))<p>{{ $encounter->examination }}</p>@endif
            @if (count($diagnoses) > 0)<p><strong>Diagnosis:</strong> {{ implode(', ', $diagnoses) }}</p>@endif
        @endif

        <h2>What was done today</h2>
        @if (count($done) === 0)
            <p class="muted">Consultation only. No treatment was carried out.</p>
        @else
            <ul>
                @foreach ($done as $row)
                    <li>{{ $row['name'] }}@if ($row['tooth']) · tooth {{ $row['tooth'] }}@endif</li>
                @endforeach
            </ul>
        @endif

        @if (filled($encounter->follow_up_note))
            <h2>Advice</h2>
            <p>{{ $encounter->follow_up_note }}</p>
        @endif

        <h2>Your next visits</h2>
        @if (count($next) === 0)
            <p class="muted">No visit is booked yet. Please call the clinic to book your next visit.</p>
        @else
            <ul>
                @foreach ($next as $row)
                    <li>{{ $row['when'] }}@if ($row['with']) · {{ $row['with'] }}@endif</li>
                @endforeach
            </ul>
        @endif

        <div class="sign"><div class="line">{{ $dentist ?? 'Dentist' }}</div></div>
        <div class="foot">If you have pain, swelling or bleeding that does not stop, call the clinic{{ $branch?->phone ? ' on '.$branch->phone : '' }} without waiting for your next visit.</div>
    </div>
</body>
</html>
