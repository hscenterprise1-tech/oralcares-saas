<?php

namespace App\Services\Physio;

use App\Models\Clinic;
use App\Models\ClinicSettingAudit;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The clinic's physiotherapy, as the owner sets it (Settings › Physio):
 *
 * - treatments: what can be given in a session (name, usual minutes, price);
 * - add-ons: what is charged on top of any session (cupping, taping), each
 *   with its price;
 * - problems (Knee pain, Back pain…): each with its session types — "Full
 *   treatment", "Exercise only", "Machine only" — the treatments each gives;
 *   a visit paid on the day costs the treatments actually given, unless the
 *   type has its own fixed (bundle) price; whether the side is asked, and the
 *   packages sold for it (sessions, price, days valid, and which session
 *   types one package session covers; none listed = every type).
 *
 * Stored in `clinics.billing_config` under `physio`. Until the owner saves
 * one, the clinic's modalities and four common problems are offered at no
 * price, so the owner only fills in prices.
 */
class PhysioCatalogue
{
    public const AUDIT_SECTION = 'physio_catalogue';

    public const SIDES = ['left' => 'Left', 'right' => 'Right', 'both' => 'Both'];

    private const MAX_ROWS = 40;

    /** Where an exercise of the clinic's library belongs (spec §16). */
    public const EXERCISE_CATEGORIES = ['knee' => 'Knee', 'back' => 'Back', 'neck' => 'Neck', 'shoulder' => 'Shoulder', 'hip' => 'Hip', 'ankle' => 'Ankle',
        'stretching' => 'Stretching', 'strengthening' => 'Strengthening', 'mobility' => 'Mobility', 'balance' => 'Balance', 'posture' => 'Posture',
        'rehabilitation' => 'Rehabilitation', 'breathing' => 'Breathing'];

    public const LEVELS = ['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard'];

    /** A starting library, until the owner sets the clinic's own: [label, category, sets, reps, hold seconds, instructions]. */
    private const DEFAULT_EXERCISES = [
        'straight_leg_raise' => ['Straight leg raise', 'knee', 3, 10, 5, 'Tighten the thigh, lift the straight leg to the other knee’s height, lower slowly.'],
        'quad_sets' => ['Quad sets', 'knee', 3, 15, 5, 'Press the back of the knee into a rolled towel.'],
        'heel_slides' => ['Heel slides', 'knee', 3, 10, 0, 'Lying down, slide the heel towards you and back.'],
        'wall_squats' => ['Wall squats', 'knee', 2, 10, 5, 'Back on the wall, bend only to 45°.'],
        'pendulum' => ['Pendulum swings', 'shoulder', 2, 10, 0, 'Lean on a table, let the arm swing in small circles.'],
        'wall_climb' => ['Wall finger climb', 'shoulder', 2, 10, 5, 'Walk the fingers up the wall as far as is comfortable.'],
        'cat_camel' => ['Cat–camel', 'back', 2, 10, 0, 'On hands and knees, round and arch the back slowly.'],
        'pelvic_tilt' => ['Pelvic tilts', 'back', 3, 10, 5, 'Lying, flatten the low back to the floor.'],
        'bridging' => ['Bridging', 'back', 3, 10, 5, 'Lift the hips, hold, lower slowly.'],
        'chin_tucks' => ['Chin tucks', 'neck', 3, 10, 5, 'Draw the chin straight back, keep the eyes level.'],
        'neck_rotation' => ['Neck rotation', 'neck', 2, 10, 0, 'Turn slowly to each side within comfort.'],
        'ankle_pumps' => ['Ankle pumps', 'ankle', 3, 20, 0, 'Point and pull the foot back.'],
    ];

    /** The default problems' usual exercises. */
    private const DEFAULT_PROBLEM_EXERCISES = [
        'knee_pain' => ['straight_leg_raise', 'quad_sets', 'heel_slides'],
        'shoulder_pain' => ['pendulum', 'wall_climb'],
        'back_pain' => ['cat_camel', 'pelvic_tilt', 'bridging'],
        'neck_pain' => ['chin_tucks', 'neck_rotation'],
    ];

    /** Common diagnoses of the default problems, until the owner sets the clinic's own. */
    private const DEFAULT_DIAGNOSES = [
        'knee_pain' => ['Patellofemoral pain', 'Knee osteoarthritis', 'Ligament sprain'],
        'shoulder_pain' => ['Frozen shoulder', 'Shoulder impingement', 'Rotator cuff strain'],
        'back_pain' => ['Lumbar spondylosis', 'Muscle spasm', 'Disc bulge with sciatica'],
        'neck_pain' => ['Cervical spondylosis', 'Neck sprain', 'Postural neck pain'],
    ];

    /**
     * @return array{treatments: list<array{key: string, label: string, minutes: int, price: float, active: bool}>, addons: list<array{key: string, label: string, minutes: int, price: float, active: bool}>, problems: list<array{key: string, label: string, sided: bool, active: bool, types: list<array{key: string, label: string, treatments: list<string>, price: ?float}>, packages: list<array{id: string, name: string, sessions: int, price: float, valid_days: int, types: list<string>}>}>}
     */
    public function resolve(Clinic $clinic): array
    {
        $stored = (array) (($clinic->billing_config ?? [])['physio'] ?? []);
        if (! isset($stored['problems'])) {
            $stored = [...$this->defaults(), ...array_intersect_key($stored, ['treatments' => true])];
        }

        return [
            'treatments' => array_values(array_map(fn (array $row) => [
                'key' => (string) $row['key'], 'label' => (string) $row['label'], 'minutes' => (int) ($row['minutes'] ?? 15),
                'price' => round((float) ($row['price'] ?? 0), 2), 'active' => (bool) ($row['active'] ?? true),
            ], (array) ($stored['treatments'] ?? []))),
            'addons' => array_values(array_map(fn (array $row) => [
                'key' => (string) $row['key'], 'label' => (string) $row['label'], 'minutes' => (int) ($row['minutes'] ?? 15),
                'price' => round((float) ($row['price'] ?? 0), 2), 'active' => (bool) ($row['active'] ?? true),
            ], (array) ($stored['addons'] ?? []))),
            'problems' => array_values(array_map(fn (array $row) => [
                'key' => (string) $row['key'], 'label' => (string) $row['label'], 'sided' => (bool) ($row['sided'] ?? true), 'active' => (bool) ($row['active'] ?? true),
                'types' => array_values(array_map(fn (array $type) => [
                    'key' => (string) $type['key'], 'label' => (string) $type['label'],
                    'treatments' => array_values(array_map('strval', (array) ($type['treatments'] ?? []))), 'price' => self::bundle($type['price'] ?? null),
                ], (array) ($row['types'] ?? []))),
                'packages' => array_values(array_map(fn (array $package) => [
                    'id' => (string) $package['id'], 'name' => (string) $package['name'], 'sessions' => (int) $package['sessions'],
                    'price' => round((float) $package['price'], 2), 'valid_days' => (int) $package['valid_days'],
                    'types' => array_values(array_map('strval', (array) ($package['types'] ?? []))),
                ], (array) ($row['packages'] ?? []))),
                // The clinic's own diagnoses for this problem: the doctor taps one.
                'diagnoses' => array_values(array_map('strval', (array) ($row['diagnoses'] ?? self::DEFAULT_DIAGNOSES[$row['key']] ?? []))),
                // The exercises usually given for it (library keys): a new plan starts with these.
                'exercises' => array_values(array_map('strval', (array) ($row['exercises'] ?? self::DEFAULT_PROBLEM_EXERCISES[$row['key']] ?? []))),
            ], (array) ($stored['problems'] ?? []))),
            'exercises' => array_values(array_map(fn (array $row) => self::exerciseRow($row), (array) ($stored['exercises'] ?? self::defaultExercises()))),
        ];
    }

    /** @return array<string, array<string, mixed>> the active exercises of the library, by key */
    public function exercises(Clinic $clinic): array
    {
        return collect($this->resolve($clinic)['exercises'])->where('active', true)->keyBy('key')->all();
    }

    /**
     * One library exercise, every field present: what it is, how it is done,
     * what to watch, and the usual sets, reps and hold.
     *
     * @param  array<string, mixed>  $row
     * @return array{key: string, label: string, category: ?string, description: ?string, instructions: ?string, precautions: ?string, level: ?string, equipment: ?string, sets: ?int, reps: ?int, hold: ?int, active: bool}
     */
    private static function exerciseRow(array $row): array
    {
        $text = fn (string $field, int $max) => ($value = mb_substr(trim((string) ($row[$field] ?? '')), 0, $max)) !== '' ? $value : null;
        $number = fn (string $field, int $max) => isset($row[$field]) && $row[$field] !== '' && $row[$field] !== null ? max(0, min($max, (int) $row[$field])) : null;

        return [
            'key' => (string) $row['key'],
            'label' => mb_substr(trim((string) $row['label']), 0, 150),
            'category' => isset(self::EXERCISE_CATEGORIES[$row['category'] ?? '']) ? $row['category'] : null,
            'description' => $text('description', 500),
            'instructions' => $text('instructions', 500),
            'precautions' => $text('precautions', 500),
            'level' => isset(self::LEVELS[$row['level'] ?? '']) ? $row['level'] : null,
            'equipment' => $text('equipment', 120),
            'sets' => $number('sets', 50),
            'reps' => $number('reps', 500),
            'hold' => $number('hold', 600),
            'active' => (bool) ($row['active'] ?? true),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function defaultExercises(): array
    {
        return array_map(fn (string $key, array $row) => ['key' => $key, 'label' => $row[0], 'category' => $row[1], 'sets' => $row[2], 'reps' => $row[3], 'hold' => $row[4] ?: null, 'instructions' => $row[5], 'level' => 'easy'],
            array_keys(self::DEFAULT_EXERCISES), self::DEFAULT_EXERCISES);
    }

    /**
     * What a visit paid on the day costs for one session type: its fixed
     * (bundle) price when the owner set one, else the price of each
     * treatment actually given.
     *
     * @param  array{price: ?float}  $type
     * @param  array<string, array{price: float}>  $treatments  the clinic's, by key
     * @param  list<string>  $given  the treatment keys given (performed or partly)
     */
    public static function visitPrice(array $type, array $treatments, array $given): float
    {
        if ($given === []) {
            return 0.0;
        }

        return $type['price'] ?? round(array_sum(array_map(fn (string $key) => (float) ($treatments[$key]['price'] ?? 0), $given)), 2);
    }

    /** A session type's fixed price: none (null) unless above zero, so the treatments given are charged. */
    private static function bundle(mixed $price): ?float
    {
        return is_numeric($price) && (float) $price > 0 ? round((float) $price, 2) : null;
    }

    /** @return array<string, array{key: string, label: string, minutes: int, price: float, active: bool}> the active treatments, by key */
    public function treatments(Clinic $clinic): array
    {
        return collect($this->resolve($clinic)['treatments'])->where('active', true)->keyBy('key')->all();
    }

    /** @return array<string, array{key: string, label: string, minutes: int, price: float, active: bool}> the active add-ons, by key */
    public function addons(Clinic $clinic): array
    {
        return collect($this->resolve($clinic)['addons'])->where('active', true)->keyBy('key')->all();
    }

    /** One active problem, or null. */
    public function problem(Clinic $clinic, ?string $key): ?array
    {
        return $key === null ? null : collect($this->resolve($clinic)['problems'])->where('active', true)->firstWhere('key', $key);
    }

    /** One session type of a problem, or null. */
    public function type(Clinic $clinic, ?string $problem, ?string $type): ?array
    {
        return collect($this->problem($clinic, $problem)['types'] ?? [])->firstWhere('key', $type);
    }

    /** One package offered for a problem, or null. */
    public function package(Clinic $clinic, ?string $problem, string $id): ?array
    {
        return collect($this->problem($clinic, $problem)['packages'] ?? [])->firstWhere('id', $id);
    }

    /**
     * The problems as the desk's visit reasons for a physiotherapy visit — one
     * per side where the side is asked ("Knee pain · Right") — so the problem
     * chosen at check-in reaches the doctor already suggested.
     *
     * @return list<array{key: string, name: string, category: null, specialty_keys: list<string>, practice_area_keys: list<string>}>
     */
    public function visitReasons(Clinic $clinic): array
    {
        return collect($this->resolve($clinic)['problems'])->where('active', true)
            ->flatMap(fn (array $problem) => $problem['sided']
                ? collect(self::SIDES)->map(fn (string $side, string $key) => ['key' => "physio:{$problem['key']}:{$key}", 'name' => "{$problem['label']} · {$side}"])->values()->all()
                : [['key' => "physio:{$problem['key']}", 'name' => $problem['label']]])
            ->map(fn (array $row) => [...$row, 'category' => null, 'specialty_keys' => [PhysioCareSummary::SPECIALTY], 'practice_area_keys' => []])
            ->values()->all();
    }

    /** @return array{problem: string, side: ?string, name: string}|null one of `visitReasons`, read back */
    public function visitReason(Clinic $clinic, string $key): ?array
    {
        $reason = collect($this->visitReasons($clinic))->firstWhere('key', $key);
        if ($reason === null) {
            return null;
        }
        $parts = explode(':', $key);

        return ['problem' => $parts[1], 'side' => $parts[2] ?? null, 'name' => $reason['name']];
    }

    /**
     * Replace the catalogue. A key, once made, is kept (courses and sessions
     * already recorded name it); a new row gets a key from its name.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(Clinic $clinic, array $input, User $by): void
    {
        $treatments = $this->rows((array) ($input['treatments'] ?? []), 'label', fn (array $row, string $key) => [
            'key' => $key, 'label' => mb_substr(trim((string) $row['label']), 0, 60), 'minutes' => $this->clamp($row['minutes'] ?? 15, 1, 180),
            'price' => $this->money($row['price'] ?? 0), 'active' => (bool) ($row['active'] ?? true),
        ]);
        $addons = $this->rows((array) ($input['addons'] ?? []), 'label', fn (array $row, string $key) => [
            'key' => $key, 'label' => mb_substr(trim((string) $row['label']), 0, 60), 'minutes' => $this->clamp($row['minutes'] ?? 15, 1, 180),
            'price' => $this->money($row['price'] ?? 0), 'active' => (bool) ($row['active'] ?? true),
        ]);
        $treatmentKeys = array_keys($treatments);
        // The exercise library: kept as it is when the form did not send it.
        $exercises = array_key_exists('exercises', $input)
            ? $this->rows((array) $input['exercises'], 'label', fn (array $row, string $key) => self::exerciseRow([...$row, 'key' => $key]), 200)
            : collect($this->resolve($clinic)['exercises'])->keyBy('key')->all();
        $exerciseKeys = array_keys($exercises);
        $problems = $this->rows((array) ($input['problems'] ?? []), 'label', function (array $row, string $key) use ($treatmentKeys, $exerciseKeys) {
            $types = $this->rows((array) ($row['types'] ?? []), 'label', fn (array $type, string $typeKey) => [
                'key' => $typeKey, 'label' => mb_substr(trim((string) $type['label']), 0, 60),
                'treatments' => array_values(array_intersect($treatmentKeys, array_map('strval', (array) ($type['treatments'] ?? [])))),
                'price' => self::bundle(is_numeric($type['price'] ?? null) ? $this->money($type['price']) : null),
            ]);
            if ($types === []) {
                throw new InvalidArgumentException('Give “'.trim((string) $row['label']).'” at least one session type.');
            }
            $packages = [];
            foreach (array_slice((array) ($row['packages'] ?? []), 0, 12) as $package) {
                $name = trim((string) ($package['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $id = (string) ($package['id'] ?? '') ?: Str::lower(Str::random(8));
                $packages[$id] = [
                    'id' => $id, 'name' => mb_substr($name, 0, 60), 'sessions' => $this->clamp($package['sessions'] ?? 10, 1, 200),
                    'price' => $this->money($package['price'] ?? 0), 'valid_days' => $this->clamp($package['valid_days'] ?? 30, 1, 730),
                    'types' => array_values(array_intersect(array_keys($types), array_map('strval', (array) ($package['types'] ?? [])))),
                ];
            }

            return ['key' => $key, 'label' => mb_substr(trim((string) $row['label']), 0, 60), 'sided' => (bool) ($row['sided'] ?? true),
                'active' => (bool) ($row['active'] ?? true), 'types' => array_values($types), 'packages' => array_values($packages),
                'diagnoses' => array_values(array_unique(array_filter(array_map(fn ($name) => mb_substr(trim((string) $name), 0, 120), array_slice((array) ($row['diagnoses'] ?? []), 0, 30))))),
                'exercises' => array_values(array_intersect($exerciseKeys, array_map('strval', (array) ($row['exercises'] ?? [])))),
            ];
        });

        $before = $this->resolve($clinic);
        $after = ['treatments' => array_values($treatments), 'addons' => array_values($addons), 'problems' => array_values($problems), 'exercises' => array_values($exercises)];
        $clinic->update(['billing_config' => [...($clinic->billing_config ?? []), 'physio' => $after]]);
        ClinicSettingAudit::create([
            'clinic_id' => $clinic->id, 'user_id' => $by->id, 'section' => self::AUDIT_SECTION,
            'old_values' => $before, 'new_values' => $after,
        ]);
    }

    /**
     * Named rows, keyed: blank names skipped, a key kept once made, two rows of one name refused.
     *
     * @param  callable(array<string, mixed>, string): array<string, mixed>  $make
     * @return array<string, array<string, mixed>>
     */
    private function rows(array $input, string $name, callable $make, int $max = self::MAX_ROWS): array
    {
        $rows = [];
        foreach (array_slice($input, 0, $max) as $row) {
            $label = trim((string) ($row[$name] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = Str::slug((string) ($row['key'] ?? '') ?: $label, '_') ?: Str::lower(Str::random(6));
            if (isset($rows[$key])) {
                throw new InvalidArgumentException("Two entries are called “{$label}”.");
            }
            $rows[$key] = $make($row, $key);
        }

        return $rows;
    }

    private function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private function money(mixed $value): float
    {
        return max(0, min(1000000, round((float) $value, 2)));
    }

    /** The starting catalogue: the clinic's modalities and common problems, unpriced. */
    private function defaults(): array
    {
        $modalities = (array) config('clinic_physio.modalities');
        $treatments = collect($modalities)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'minutes' => 15, 'active' => true])->values()->all();
        $has = fn (array $keys) => array_values(array_intersect(array_keys($modalities), $keys));
        $types = fn () => [
            ['key' => 'full', 'label' => 'Full treatment', 'treatments' => $has(['ultrasound', 'ift', 'exercise_therapy']), 'price' => 0],
            ['key' => 'exercise', 'label' => 'Exercise only', 'treatments' => $has(['exercise_therapy']), 'price' => 0],
            ['key' => 'machine', 'label' => 'Machine only', 'treatments' => $has(['ultrasound', 'ift']), 'price' => 0],
        ];

        return [
            'treatments' => $treatments,
            'addons' => [
                ['key' => 'cupping', 'label' => 'Cupping', 'minutes' => 15, 'price' => 0, 'active' => true],
                ['key' => 'taping', 'label' => 'Taping', 'minutes' => 10, 'price' => 0, 'active' => true],
            ],
            'problems' => collect(['knee_pain' => 'Knee pain', 'shoulder_pain' => 'Shoulder pain', 'back_pain' => 'Lower back pain', 'neck_pain' => 'Neck pain'])
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'sided' => ! in_array($key, ['back_pain', 'neck_pain'], true), 'active' => true, 'types' => $types(), 'packages' => []])
                ->values()->all(),
        ];
    }
}
