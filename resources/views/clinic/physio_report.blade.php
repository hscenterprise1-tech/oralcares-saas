@php
    /**
     * One physiotherapy report of a problem (`ConsultationController::physioReport`), made only from its record:
     * `$course` is the workspace's own entry for it, `$sessions` its completed sessions, `$labels` the choice labels.
     */
    $branchAddress = $branch ? collect([$branch->address_line1, $branch->address_line2, $branch->city, $branch->state, $branch->pincode])->filter()->implode(', ') : null;
    $age = $patient->date_of_birth ? (int) $patient->date_of_birth->diffInYears(now()) : $patient->age;
    $label = fn (string $list, ?string $key) => $key ? ($labels[$list][$key] ?? $key) : null;
    $many = fn (string $list, ?array $keys) => collect($keys ?? [])->map(fn ($key) => $labels[$list][$key] ?? $key)->implode(', ');
    $day = fn (?string $iso) => $iso ? \Carbon\CarbonImmutable::parse($iso)->format('d M Y') : '—';
    $complaint = $course['details']['complaint'] ?? null;
    $plan = $course['details']['plan'] ?? null;
    $progress = $course['progress'];
    $statuses = ['performed' => 'Done', 'partial' => 'Partly', 'not_performed' => 'Not done', 'skipped' => 'Not done'];
    $feels = ['better' => 'Better', 'same' => 'Same', 'worse' => 'Worse'];
    $rupee = fn ($n) => '₹'.number_format((float) $n, 0);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} · {{ $course['title'] }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #101828; font-size: 11.5px; line-height: 1.5; margin: 0; background: #f2f4f7; }
        .page { background: #fff; max-width: 190mm; margin: 16px auto; padding: 18px 22px; }
        .bar { text-align: center; padding: 12px; }
        .bar button, .bar a { display: inline-block; font: 600 13px Helvetica, Arial, sans-serif; padding: 10px 18px; border-radius: 999px; border: 0; background: #101828; color: #fff; cursor: pointer; text-decoration: none; margin: 0 4px; }
        .bar a { background: #fff; color: #101828; border: 1px solid #d0d5dd; }
        table { width: 100%; border-collapse: collapse; }
        .clinic { font-size: 16px; font-weight: 700; }
        .muted { color: #475467; }
        .right { text-align: right; }
        .rule { border-top: 2px solid #101828; margin: 10px 0 8px; }
        h1 { font-size: 18px; margin: 6px 0 0; }
        h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; color: #475467; margin: 16px 0 6px; border-bottom: 1px solid #eaecf0; padding-bottom: 4px; }
        .facts td { padding: 3px 0; vertical-align: top; }
        .facts td.k { width: 34%; color: #475467; }
        .lines th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #475467; border-bottom: 1px solid #d0d5dd; padding: 5px 4px; }
        .lines td { padding: 6px 4px; border-bottom: 1px solid #eaecf0; vertical-align: top; }
        .tag { display: inline-block; padding: 1px 6px; border-radius: 6px; background: #f2f4f7; font-size: 10.5px; margin: 1px 2px 1px 0; }
        .done { background: #e6f6f3; color: #0e7c6b; }
        .part { background: #fdf3e7; color: #a35a06; }
        .skip { background: #f2f4f7; color: #667085; text-decoration: line-through; }
        .sign { margin-top: 26px; }
        @media print { body { background: #fff; } .page { margin: 0; max-width: none; padding: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
@unless ($forDownload)
    <div class="bar no-print">
        <button type="button" onclick="window.print()">Print</button>
        <a href="{{ request()->fullUrlWithQuery(['download' => 1]) }}">Download PDF</a>
    </div>
@endunless

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
                <div class="muted">{{ $patient->uhid }}@if ($age) · {{ $age }} y @endif @if ($patient->gender) · {{ ucfirst($patient->gender) }} @endif</div>
                <div class="muted">Printed {{ $printedOn->format('d M Y') }}</div>
            </td>
        </tr>
    </table>
    <div class="rule"></div>
    <h1>{{ $title }} · {{ $course['title'] }}</h1>
    <div class="muted">{{ $course['status'] === 'active' ? 'Active' : 'Not started' }}@if ($course['started']) since {{ $day($course['started']) }}@endif @if ($course['diagnosis']) · {{ $course['diagnosis'] }} @endif</div>

    @if ($kind === 'assessment')
        <h2>Complaint</h2>
        <table class="facts">
            <tr><td class="k">Problem</td><td>{{ $course['title'] }}</td></tr>
            <tr><td class="k">Since</td><td>{{ $label('durations', $complaint['duration'] ?? null) ?? '—' }}</td></tr>
            <tr><td class="k">Pain</td><td>{{ $label('severities', $complaint['severity'] ?? null) ?? '—' }}</td></tr>
            <tr><td class="k">Daily difficulty</td><td>{{ $many('difficulties', $complaint['difficulties'] ?? []) ?: '—' }}</td></tr>
            <tr><td class="k">Tried before</td><td>{{ $many('previous', $complaint['previous'] ?? []) ?: '—' }}</td></tr>
            @if (! empty($complaint['description']))<tr><td class="k">Findings</td><td>{{ $complaint['description'] }}</td></tr>@endif
            @if (! empty($complaint['remarks']))<tr><td class="k">Remarks</td><td>{{ $complaint['remarks'] }}</td></tr>@endif
        </table>
        <h2>Diagnosis</h2>
        <p>{{ $course['diagnosis'] ?? 'Not recorded.' }}</p>
        <p class="muted">Recorded by {{ $course['details']['recorded_by'] ?? '—' }} on {{ $day($course['details']['recorded_at'] ?? null) }}@if ($course['details']['revisions'] > 0) · {{ $course['details']['revisions'] }} earlier {{ $course['details']['revisions'] === 1 ? 'version' : 'versions' }} kept @endif</p>
    @endif

    @if ($kind === 'plan')
        <h2>Plan</h2>
        <table class="facts">
            <tr><td class="k">Session type</td><td>{{ $type['label'] ?? '—' }}</td></tr>
            <tr><td class="k">Each visit gives</td><td>{{ collect($type['treatments'] ?? [])->map(fn ($key) => $treatments[$key]['label'] ?? $key)->implode(', ') ?: '—' }}</td></tr>
            <tr><td class="k">How often</td><td>{{ $frequency ?? '—' }}</td></tr>
            <tr><td class="k">Sessions</td><td>{{ $course['done'] }}{{ $course['planned'] ? ' of '.$course['planned'] : '' }} done</td></tr>
            <tr><td class="k">Aim</td><td>{{ $label('categories', $plan['category'] ?? null) ?? '—' }}</td></tr>
            <tr><td class="k">Goals</td><td>{{ $course['goals'] ?: '—' }}</td></tr>
            <tr><td class="k">Advice</td><td>{{ $plan['instructions'] ?? '—' }}</td></tr>
            <tr><td class="k">Payment</td><td>{{ $course['package'] ? $course['package']['name'].' · '.$course['package']['left'].' of '.$course['package']['total'].' left' : 'Per visit' }}</td></tr>
        </table>
        <h2>Exercises</h2>
        @if (empty($course['exercises']))
            <p class="muted">No exercises prescribed.</p>
        @else
            <table class="lines">
                <thead><tr><th>Exercise</th><th>Sets × reps</th><th>Hold</th><th>How often</th></tr></thead>
                <tbody>
                @foreach ($course['exercises'] as $exercise)
                    <tr><td><strong>{{ $exercise['name'] }}</strong></td><td>{{ $exercise['sets'] ?? '—' }} × {{ $exercise['reps'] ?? '—' }}</td><td>{{ $exercise['hold'] ? $exercise['hold'].' s' : '—' }}</td><td>{{ $exercise['per_day'] ? $exercise['per_day'].'× a day' : '—' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if ($kind === 'session' && $session)
        <h2>Session {{ $session['session'] }} · {{ $day($session['date']) }}</h2>
        <table class="facts">
            <tr><td class="k">Type</td><td>{{ $session['type'] ?? '—' }}</td></tr>
            <tr><td class="k">Given by</td><td>{{ implode(', ', $session['by']) ?: '—' }}</td></tr>
            <tr><td class="k">Patient felt</td><td>{{ $feels[$session['feel']] ?? '—' }}</td></tr>
            <tr><td class="k">Paid</td><td>{{ $session['package'] ? $session['package'].' · '.$session['package_use'] : ($session['charged'] ? $rupee($session['charged']) : 'No charge') }}</td></tr>
        </table>
        <h2>Treatments</h2>
        <table class="lines">
            <thead><tr><th>Treatment</th><th>Minutes</th><th>By</th><th>Status</th></tr></thead>
            <tbody>
            @foreach ($session['items'] as $item)
                <tr><td>{{ $item['label'] }}</td><td>{{ $item['minutes'] ?? '—' }}</td><td>{{ $item['by'] ?? '—' }}</td><td>{{ $statuses[$item['status'] ?? 'performed'] ?? 'Done' }}</td></tr>
            @endforeach
            @foreach ($session['addons'] as $addon)
                <tr><td>+ {{ $addon }}</td><td>—</td><td>—</td><td>Add-on</td></tr>
            @endforeach
            </tbody>
        </table>
        @if (! empty($session['exercises']))
            <h2>Exercises</h2>
            <table class="lines">
                <thead><tr><th>Exercise</th><th>Done</th><th>Status</th></tr></thead>
                <tbody>
                @foreach ($session['exercises'] as $exercise)
                    <tr><td>{{ $exercise['name'] }}</td><td>{{ $exercise['sets'] ? $exercise['sets'].' × '.($exercise['reps'] ?? '—') : '—' }}</td><td>{{ $statuses[$exercise['status']] ?? $exercise['status'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
        @if ($session['note'])<h2>Note</h2><p>{{ $session['note'] }}</p>@endif
    @endif

    @if ($kind === 'progress')
        <h2>Then and now</h2>
        <table class="facts">
            <tr><td class="k">Then @if ($progress['initial']) ({{ $day($progress['initial']['on']) }}) @endif</td><td>{{ $progress['initial'] ? collect([$label('severities', $progress['initial']['complaint']['severity'] ?? null), $many('difficulties', $progress['initial']['complaint']['difficulties'] ?? [])])->filter()->implode(' · ') : '—' }}</td></tr>
            <tr><td class="k">Now</td><td>{{ $progress['now'] ? collect([$label('severities', $progress['now']['severity'] ?? null), $many('difficulties', $progress['now']['difficulties'] ?? [])])->filter()->implode(' · ') : '—' }}</td></tr>
            <tr><td class="k">Sessions</td><td>{{ $course['done'] }}{{ $course['planned'] ? ' of '.$course['planned'] : '' }} done · last {{ $day($progress['last_session']) }}</td></tr>
            <tr><td class="k">Patient felt</td><td>@forelse ($progress['feels'] as $feel)<span class="tag">{{ $feels[$feel['feel']] ?? '—' }}</span>@empty — @endforelse</td></tr>
            <tr><td class="k">Next review</td><td>{{ $day($progress['review_on']) }}</td></tr>
        </table>
        <h2>Goals</h2>
        @forelse ($progress['goals'] as $goal)
            <div><span class="tag {{ $goal['done_on'] ? 'done' : '' }}">{{ $goal['done_on'] ? 'Reached '.$day($goal['done_on']) : 'Pending' }}</span> {{ $goal['text'] }}</div>
        @empty
            <p class="muted">No goals set.</p>
        @endforelse
        <h2>Progress notes</h2>
        @forelse ($progress['notes'] as $note)
            <div><strong>{{ $day($note['on']) }}</strong>@if ($note['review']) <span class="tag part">Review</span>@endif {{ $note['text'] }} <span class="muted">— {{ $note['by'] }}</span></div>
        @empty
            <p class="muted">No notes yet.</p>
        @endforelse
        <h2>Sessions</h2>
        <table class="lines">
            <thead><tr><th>Date</th><th>Type</th><th>Given</th><th>Felt</th></tr></thead>
            <tbody>
            @forelse ($sessions as $row)
                <tr>
                    <td>{{ $day($row['date']) }}</td>
                    <td>{{ $row['type'] ?? '—' }}</td>
                    <td>@foreach ($row['items'] as $item)<span class="tag {{ ($item['status'] ?? 'performed') === 'not_performed' ? 'skip' : (($item['status'] ?? '') === 'partial' ? 'part' : 'done') }}">{{ $item['label'] }}</span>@endforeach</td>
                    <td>{{ $feels[$row['feel']] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">No sessions yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif

    @if ($kind === 'package')
        <h2>Packages</h2>
        @if (empty($course['packages']))
            <p class="muted">No package bought for this problem; visits are paid one by one.</p>
        @else
            <table class="lines">
                <thead><tr><th>Package</th><th>Bought</th><th>Used / left</th><th>Valid till</th><th>Status</th><th>Bill</th></tr></thead>
                <tbody>
                @foreach ($course['packages'] as $package)
                    <tr>
                        <td><strong>{{ $package['name'] }}</strong></td>
                        <td>{{ $day($package['sold_on']) }}</td>
                        <td>{{ $package['used'] }} / {{ $package['left'] }}</td>
                        <td>{{ $day($package['valid_until']) }}</td>
                        <td>{{ ['active' => 'Running', 'used' => 'Used up', 'expired' => 'Expired'][$package['state']] ?? ucfirst($package['state']) }}</td>
                        <td>@if ($package['bill']){{ $package['bill']['number'] }} · {{ $rupee($package['bill']['amount']) }} · {{ $package['bill']['due'] > 0 ? $rupee($package['bill']['due']).' due' : 'paid' }}@else — @endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
        <h2>Sessions paid from a package</h2>
        <table class="lines">
            <thead><tr><th>Date</th><th>Session</th><th>Package</th></tr></thead>
            <tbody>
            @forelse ($sessions->filter(fn ($row) => $row['package']) as $row)
                <tr><td>{{ $day($row['date']) }}</td><td>{{ $row['session'] }}</td><td>{{ $row['package'] }} · {{ $row['package_use'] }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">None yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif

    <div class="sign">
        <div>{{ $therapist ?? '' }}</div>
        <div class="muted">Made from the clinic’s record on {{ $printedOn->format('d M Y, h:i A') }}.</div>
    </div>
</div>
</body>
</html>
