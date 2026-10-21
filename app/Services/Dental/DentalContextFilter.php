<?php

namespace App\Services\Dental;

use App\LabCaseStatus;
use App\Models\EndodonticRecord;
use App\Models\ImplantRecord;
use App\Models\LabOrder;
use App\Models\OrthodonticCase;
use App\Models\Patient;
use App\Models\PatientDentalChart;
use App\Models\PeriodontalExam;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use Illuminate\Support\Collection;

/**
 * Which teeth matter in which context, decided on the server.
 *
 * The same dental record is shown differently by role and workflow: the
 * front desk sees the teeth under treatment, a treatment plan its own teeth,
 * root canal work its teeth, and so on. This class works that out from the
 * structured record — chart, plans, specialty records, lab cases,
 * periodontal exam — so a view never needs the whole mouth to filter it.
 *
 * It also decides each tooth's state on the shared legend
 * (`config('clinic_dental.states')`), and builds "Ongoing treatment": only
 * teeth with live treatment context, nothing settled.
 */
class DentalContextFilter
{
    private const TO_DO = ['proposed', 'planned'];

    private const UNDER_WAY = ['started', 'paused', 'resumed'];

    /** Lab cases still with the lab or awaiting fitting. */
    private const LAB_OPEN = ['draft', 'requested', 'accepted', 'in_production', 'quality_check', 'ready_for_delivery', 'delivered'];

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(protected DentalRecord $dental) {}

    /**
     * Everything the views need, built once per request.
     *
     * @return array{chart: Collection<int, PatientDentalChart>, plans: Collection<int, TreatmentPlan>, endo: Collection<int, EndodonticRecord>, implants: Collection<int, ImplantRecord>, ortho: ?OrthodonticCase, lab: Collection<int, LabOrder>, perio: ?PeriodontalExam}
     */
    public function record(Patient $patient): array
    {
        // Keyed to this request: the builders that hold this class can outlive one.
        return $this->cache[spl_object_id(request()).':'.$patient->id] ??= [
            'chart' => $this->dental->chart($patient),
            'plans' => TreatmentPlan::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->with(['records' => fn ($query) => $query->orderBy('sort_order'), 'author:id,name'])->latest('id')->limit(20)->get(),
            'endo' => EndodonticRecord::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->whereNull('voided_at')->with('author:id,name')->latest('id')->limit(30)->get(),
            'implants' => ImplantRecord::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->whereNull('voided_at')->with('author:id,name')->latest('id')->limit(30)->get(),
            'ortho' => OrthodonticCase::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->whereNull('voided_at')->with(['visits' => fn ($query) => $query->limit(12), 'visits.recorder:id,name'])->latest('id')->first(),
            'lab' => LabOrder::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->latest('id')->limit(15)->get(),
            'perio' => PeriodontalExam::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->with('teeth')->latest('id')->first(),
        ];
    }

    /**
     * Each charted or treated tooth's state on the shared legend.
     *
     * @return array<int, string>
     */
    public function states(Patient $patient): array
    {
        $r = $this->record($patient);
        $items = $this->openItems($r['plans']);
        $teeth = collect($r['chart']->keys())
            ->merge($items->pluck('tooth_number'))->merge($r['endo']->pluck('tooth_number'))->merge($r['implants']->pluck('tooth_number'))
            ->merge($this->labTeeth($r['lab']))->filter()->map(fn ($t) => (int) $t)->unique();

        return $teeth->mapWithKeys(function (int $tooth) use ($r, $items) {
            $category = config('clinic_dental.conditions.'.($r['chart']->get($tooth)?->status ?? 'sound').'.category');
            $toothItems = $items->where('tooth_number', $tooth);
            $endo = $r['endo']->where('tooth_number', $tooth)->first();
            $implant = $r['implants']->where('tooth_number', $tooth)->first();
            $lab = $r['lab']->first(fn (LabOrder $o) => in_array($this->labStatus($o), self::LAB_OPEN, true) && in_array($tooth, array_map('intval', (array) $o->requested_teeth), true));

            $state = match (true) {
                $category === 'urgent' => 'urgent',
                $toothItems->contains(fn (TreatmentRecord $i) => in_array($i->status?->value, self::UNDER_WAY, true))
                    || $endo?->status === 'in_progress'
                    || in_array($implant?->status, ['placed', 'integrating'], true)
                    || $lab !== null => 'in_progress',
                $toothItems->contains(fn (TreatmentRecord $i) => in_array($i->status?->value, self::TO_DO, true))
                    || $endo?->status === 'planned' || $implant?->status === 'planned' => 'planned',
                in_array($category, ['pathology', 'observation'], true) || ($category === 'endodontic' && ($r['chart']->get($tooth)?->status === 'periapical')) => 'condition',
                $toothItems->contains(fn (TreatmentRecord $i) => $i->status?->value === 'completed') => 'completed',
                $category === 'missing' => 'missing',
                $category === 'implant' || $implant?->status === 'restored' => 'implant',
                in_array($category, ['restoration', 'prosthetic', 'endodontic', 'orthodontic'], true) || $endo?->status === 'completed' => 'restored',
                default => 'healthy',
            };

            return [$tooth => $state];
        })->all();
    }

    /**
     * Ongoing treatment: teeth with live treatment, each with what is being
     * done and where it stands. Treatment from a finished plan drops out.
     * Pass sources (plan, endodontic, implant, lab, orthodontic) to keep only
     * those rows; the filter runs before rows for the same work collapse.
     *
     * @param  list<string>|null  $sources
     * @return list<array{key: string, tooth: ?int, procedure: string, status: string, state: string, next_step: ?string, source: string, price: ?float, billed: ?bool, record_id: ?int}>
     */
    public function ongoing(Patient $patient, ?array $sources = null): array
    {
        $r = $this->record($patient);
        $states = $this->states($patient);
        $rows = collect();

        foreach ($this->openItems($r['plans'], true) as $item) {
            $status = match (true) {
                in_array($item->status?->value, self::UNDER_WAY, true) => 'in_progress',
                $item->status?->value === 'completed' => 'completed',
                default => 'planned',
            };
            $rows->push([
                'key' => "plan-item-{$item->id}", 'tooth' => $item->tooth_number ? (int) $item->tooth_number : null,
                'procedure' => preg_replace('/ — .*/', '', (string) $item->description), 'status' => $status,
                'state' => $status, 'next_step' => $status === 'planned' ? 'To be done' : null, 'source' => 'plan',
                'price' => $item->price !== null ? (float) $item->price : null, 'billed' => null, 'record_id' => $item->id,
            ]);
        }
        foreach ($r['endo']->whereIn('status', ['planned', 'in_progress']) as $endo) {
            $rows->push([
                'key' => "endo-{$endo->id}", 'tooth' => $endo->tooth_number, 'procedure' => 'Root canal treatment',
                'status' => $endo->status === 'planned' ? 'planned' : 'in_progress', 'state' => $endo->status === 'planned' ? 'planned' : 'in_progress',
                'next_step' => 'Stage: '.str_replace('_', ' & ', $endo->stage), 'source' => 'endodontic', 'price' => null, 'billed' => null, 'record_id' => $endo->id,
            ]);
        }
        foreach ($r['implants']->whereIn('status', ['planned', 'placed', 'integrating']) as $implant) {
            $rows->push([
                'key' => "implant-{$implant->id}", 'tooth' => $implant->tooth_number, 'procedure' => 'Implant',
                'status' => $implant->status === 'planned' ? 'planned' : 'in_progress', 'state' => $implant->status === 'planned' ? 'planned' : 'in_progress',
                'next_step' => match ($implant->status) {
                    'planned' => 'Placement', 'placed' => 'Healing', default => $implant->crown_status === 'final' ? 'Review' : 'Abutment & crown'
                },
                'source' => 'implant', 'price' => null, 'billed' => null, 'record_id' => $implant->id,
            ]);
        }
        foreach ($r['lab']->filter(fn (LabOrder $o) => in_array($this->labStatus($o), self::LAB_OPEN, true)) as $order) {
            foreach (array_map('intval', (array) $order->requested_teeth) ?: [null] as $tooth) {
                $rows->push([
                    'key' => "lab-{$order->id}-{$tooth}", 'tooth' => $tooth, 'procedure' => ($order->restoration ?? 'Lab work').' (lab)',
                    'status' => 'in_progress', 'state' => 'in_progress', 'next_step' => self::labLabel($this->labStatus($order)), 'source' => 'lab',
                    'price' => null, 'billed' => null, 'record_id' => $order->id,
                ]);
            }
        }
        if ($r['ortho'] && $r['ortho']->status === 'active') {
            $rows->push([
                'key' => "ortho-{$r['ortho']->id}", 'tooth' => null, 'procedure' => 'Orthodontics · '.($r['ortho']->arches === 'both' ? 'both arches' : "{$r['ortho']->arches} arch"),
                'status' => 'in_progress', 'state' => 'in_progress', 'next_step' => 'Phase: '.$r['ortho']->phase, 'source' => 'orthodontic', 'price' => null, 'billed' => null, 'record_id' => $r['ortho']->id,
            ]);
        }

        // One tooth, one procedure: a plan item and the specialty or lab record
        // for the same work collapse, keeping whichever is further along.
        $rank = fn (array $row) => ['in_progress' => 0, 'planned' => 1, 'completed' => 2][$row['status']] ?? 3;

        return $rows->when($sources !== null, fn ($rows) => $rows->whereIn('source', $sources))->sortBy($rank)
            ->unique(fn (array $row) => ($row['tooth'] ?? 'arch').'|'.strtolower(substr($row['procedure'], 0, 10)))
            ->sortBy(fn (array $row) => [$rank($row), $row['tooth'] ?? 99])
            ->map(fn (array $row) => [...$row, 'state' => $row['tooth'] ? ($states[$row['tooth']] ?? $row['state']) : $row['state']])
            ->values()->all();
    }

    /**
     * The teeth each context shows.
     *
     * @return array<string, mixed>
     */
    public function contexts(Patient $patient): array
    {
        $r = $this->record($patient);
        $chart = $r['chart'];
        $byCategory = fn (string $category) => $chart->filter(fn (PatientDentalChart $t) => config("clinic_dental.conditions.{$t->status}.category") === $category)->keys()->map(fn ($t) => (int) $t)->values()->all();
        $perioTeeth = $r['perio']?->teeth->filter(fn ($t) => ! $t->missing && (collect($t->probing_depths ?? [])->contains(fn ($v) => $v !== null && $v >= 4) || collect($t->bleeding ?? [])->contains(true)))
            ->pluck('tooth_number')->map(fn ($t) => (int) $t)->values()->all() ?? [];

        return [
            'ongoing' => collect($this->ongoing($patient))->pluck('tooth')->filter()->unique()->values()->all(),
            'plans' => $r['plans']->mapWithKeys(fn (TreatmentPlan $plan) => [$plan->id => $plan->records->pluck('tooth_number')->filter()->map(fn ($t) => (int) $t)->unique()->values()->all()])->all(),
            'endodontic' => collect($byCategory('endodontic'))->merge($r['endo']->pluck('tooth_number'))->unique()->sort()->values()->all(),
            'implant' => collect($byCategory('implant'))->merge($r['implants']->pluck('tooth_number'))->unique()->sort()->values()->all(),
            'prosthetic' => collect($byCategory('prosthetic'))->merge($this->labTeeth($r['lab']))->unique()->sort()->values()->all(),
            'orthodontic' => [
                'arches' => $r['ortho']?->arches,
                'teeth' => collect($r['ortho']?->bracketed_teeth ?? [])->merge($byCategory('orthodontic'))->unique()->sort()->values()->all(),
            ],
            'periodontal' => $perioTeeth,
        ];
    }

    public static function labLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Ordered — not yet sent',
            'requested', 'accepted' => 'Sent to lab',
            'in_production', 'quality_check', 'ready_for_delivery' => 'In progress at lab',
            'delivered' => 'Received — awaiting fitting',
            'fitted' => 'Fitted',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public function labStatus(LabOrder $order): string
    {
        return $order->status instanceof LabCaseStatus ? $order->status->value : (string) $order->status;
    }

    /**
     * Items of plans that are still running (completed ones too, when asked).
     *
     * @param  Collection<int, TreatmentPlan>  $plans
     * @return Collection<int, TreatmentRecord>
     */
    private function openItems(Collection $plans, bool $withCompleted = false): Collection
    {
        return $plans->reject(fn (TreatmentPlan $plan) => in_array($plan->status, TreatmentPlan::CLOSED, true))
            ->flatMap->records
            ->filter(fn (TreatmentRecord $item) => in_array($item->status?->value, [...self::TO_DO, ...self::UNDER_WAY, ...($withCompleted ? ['completed'] : [])], true));
    }

    /**
     * @param  Collection<int, LabOrder>  $lab
     * @return list<int>
     */
    private function labTeeth(Collection $lab): array
    {
        return $lab->filter(fn (LabOrder $o) => in_array($this->labStatus($o), self::LAB_OPEN, true))
            ->flatMap(fn (LabOrder $o) => array_map('intval', (array) $o->requested_teeth))->unique()->values()->all();
    }
}
