@php
    /** The patient's home exercise sheet for one physiotherapy problem (`ConsultationController::physioExerciseSheet`). */
    $branchAddress = $branch ? collect([$branch->address_line1, $branch->address_line2, $branch->city, $branch->state, $branch->pincode])->filter()->implode(', ') : null;
    $age = $patient->date_of_birth ? (int) $patient->date_of_birth->diffInYears(now()) : $patient->age;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exercises · {{ $course->title }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #101828; font-size: 12px; line-height: 1.45; margin: 0; background: #f2f4f7; }
        .page { background: #fff; max-width: 190mm; margin: 16px auto; padding: 18px 22px; }
        .bar { text-align: center; padding: 12px; }
        .bar button { font: 600 13px Helvetica, Arial, sans-serif; padding: 10px 18px; border-radius: 999px; border: 0; background: #101828; color: #fff; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        .clinic { font-size: 16px; font-weight: 700; }
        .muted { color: #475467; }
        .right { text-align: right; }
        .rule { border-top: 2px solid #101828; margin: 10px 0 8px; }
        .title { font-size: 18px; font-weight: 700; margin: 6px 0 2px; }
        .lines th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #475467; border-bottom: 1px solid #d0d5dd; padding: 6px 4px; }
        .lines td { padding: 9px 4px; border-bottom: 1px solid #eaecf0; vertical-align: top; }
        .name { font-weight: 700; font-size: 13px; }
        .dose { white-space: nowrap; font-weight: 600; }
        .box { margin-top: 12px; padding: 10px 12px; border: 1px solid #d0d5dd; border-radius: 8px; }
        .sign { margin-top: 26px; }
        @media print { body { background: #fff; } .page { margin: 0; max-width: none; padding: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
<div class="bar no-print"><button type="button" onclick="window.print()">Print exercise sheet</button></div>

<div class="page">
    <table>
        <tr>
            <td>
                <div class="clinic">{{ $clinic->name }}</div>
                @if ($branch && $branch->name !== $clinic->name)<div>{{ $branch->name }}</div>@endif
                @if ($branchAddress)<div class="muted">{{ $branchAddress }}</div>@endif
            </td>
            <td class="right">
                <div><strong>{{ $patient->full_name }}</strong></div>
                <div class="muted">{{ $patient->uhid }}@if ($age) · {{ $age }} y @endif</div>
                <div class="muted">{{ $printedOn->format('d M Y') }}</div>
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    <div class="title">Home exercises · {{ $course->title }}</div>
    @if ($diagnosis)<div class="muted">Diagnosis: {{ $diagnosis }}</div>@endif

    @if ($exercises->isEmpty())
        <p class="muted">No exercises are prescribed yet.</p>
    @else
        <table class="lines" style="margin-top: 10px">
            <thead>
                <tr><th>#</th><th>Exercise</th><th>Sets × reps</th><th>Hold</th><th>How often</th><th>How to do it</th></tr>
            </thead>
            <tbody>
                @foreach ($exercises as $exercise)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td class="name">{{ $exercise->name }}</td>
                        <td class="dose">{{ $exercise->sets ?? '—' }} × {{ $exercise->reps ?? '—' }}</td>
                        <td class="dose">{{ $exercise->hold_seconds ? $exercise->hold_seconds.' s' : '—' }}</td>
                        <td class="dose">{{ $exercise->frequency ?? '—' }}</td>
                        <td>{{ $exercise->instructions }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($advice || $goals)
        <div class="box">
            @if ($goals)<div><strong>Goals:</strong> {{ $goals }}</div>@endif
            @if ($advice)<div><strong>Advice:</strong> {{ $advice }}</div>@endif
        </div>
    @endif

    <div class="sign">
        <div>{{ $therapist ?? '' }}</div>
        <div class="muted">Stop an exercise that causes sharp pain, and tell your physiotherapist.</div>
    </div>
</div>
</body>
</html>
