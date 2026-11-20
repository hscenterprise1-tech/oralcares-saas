<?php

namespace App\Services\Onboarding\Sections;

use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use App\Services\Onboarding\OnboardingContext;
use App\Services\ProcedureCatalogService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The services the clinic offers, with a price and a usual duration.
 *
 * `prepare()` adds the template's suggested services (switched off) the
 * first time onboarding starts, so the owner confirms each one instead of
 * typing a menu from scratch, and nothing goes live priced without them.
 * Prices are clinic-wide; branch prices are set later in settings.
 */
class ServicesSection extends AbstractSection
{
    public const MARKER = 'Suggested during onboarding.';

    public function key(): string
    {
        return 'services';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Services & pricing';
    }

    public function description(OnboardingContext $context): string
    {
        return 'Switch on the services you offer and set a price and usual duration for each. You can add your own.';
    }

    public function prepare(OnboardingContext $context): void
    {
        $existing = ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $context->clinic->id)->pluck('code')->all();

        foreach ($this->suggestions($context) as $suggestion) {
            if (in_array($suggestion['code'], $existing, true)) {
                continue;
            }

            ClinicalProcedure::withoutGlobalScopes()->create([
                'clinic_id' => $context->clinic->id,
                'code' => $suggestion['code'],
                'name' => $suggestion['name'],
                'category' => $suggestion['category'],
                'description' => self::MARKER,
                'default_duration_minutes' => $suggestion['duration'],
                'is_active' => false,
            ]);
            $existing[] = $suggestion['code'];
        }
    }

    public function initial(OnboardingContext $context): array
    {
        $suggested = collect($this->suggestions($context))->keyBy('code');
        $prices = ClinicalProcedurePrice::withoutGlobalScopes()
            ->where('clinic_id', $context->clinic->id)
            ->whereNull('branch_id')
            ->get()
            ->keyBy('clinical_procedure_id');

        $services = ClinicalProcedure::withoutGlobalScopes()
            ->where('clinic_id', $context->clinic->id)
            ->orderBy('category')
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(function (ClinicalProcedure $procedure) use ($prices, $suggested) {
                $price = $prices->get($procedure->id);
                $untouched = $procedure->description === self::MARKER && ! $procedure->is_active && ! $price;

                return [
                    'id' => $procedure->id,
                    'name' => $procedure->name,
                    'category' => $procedure->category,
                    'duration' => (int) ($price?->duration_minutes ?? $procedure->default_duration_minutes ?? 30),
                    'price' => $price ? (float) $price->price : ($untouched ? ($suggested->get($procedure->code)['price'] ?? null) : null),
                    // The everyday suggestions (`core`) start switched on, so the first save adopts them; the rest of a long list wait for the owner to choose.
                    'active' => $untouched ? (bool) ($suggested->get($procedure->code)['core'] ?? false) : (bool) $procedure->is_active,
                ];
            })
            ->values()
            ->all();

        return ['services' => $services];
    }

    public function rules(OnboardingContext $context): array
    {
        return [
            'services' => ['required', 'array', 'min:1', 'max:200'],
            'services.*.id' => ['nullable', 'integer', Rule::exists('clinical_procedures', 'id')->where('clinic_id', $context->clinic->id)],
            'services.*.name' => ['required', 'string', 'max:120'],
            'services.*.category' => ['nullable', 'string', 'max:80'],
            'services.*.duration' => ['required', 'integer', 'min:5', 'max:480'],
            'services.*.price' => ['nullable', 'required_if:services.*.active,true', 'numeric', 'min:0', 'max:10000000'],
            'services.*.active' => ['required', 'boolean'],
        ];
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        if (! collect($data['services'])->contains(fn (array $service) => $service['active'])) {
            throw ValidationException::withMessages(['services' => 'Switch on at least one service.']);
        }

        foreach ($data['services'] as $row) {
            $procedure = ! empty($row['id'])
                ? ClinicalProcedure::withoutGlobalScopes()->where('clinic_id', $context->clinic->id)->findOrFail($row['id'])
                : ClinicalProcedure::withoutGlobalScopes()->firstOrNew(['clinic_id' => $context->clinic->id, 'name' => $row['name']]);

            $procedure->fill([
                'name' => $row['name'],
                'category' => $row['category'] ?? $procedure->category ?? 'General',
                'default_duration_minutes' => $row['duration'],
                'is_active' => $row['active'],
                'description' => $procedure->description === self::MARKER ? null : $procedure->description,
            ]);
            $procedure->code ??= 'SVC-'.Str::upper(Str::random(6));
            $procedure->save();

            // A new price version (and the legacy row kept in step).
            app(ProcedureCatalogService::class)->setClinicPricing($context->clinic, $procedure, (float) ($row['price'] ?? 0), $row['duration'], (bool) $row['active']);
        }
    }

    public function summary(OnboardingContext $context): array
    {
        $active = collect($this->initial($context)['services'])->where('active', true);

        return [
            $active->count().' services offered',
            $active->take(4)->map(fn (array $service) => $service['name'].' ₹'.number_format((float) $service['price']))->implode(', '),
        ];
    }

    /**
     * @return list<array{code: string, name: string, category: string, duration: int, price: int}>
     */
    private function suggestions(OnboardingContext $context): array
    {
        return collect((array) $context->option('catalogues', []))
            ->flatMap(fn (string $catalogue) => config("clinic_onboarding.catalogues.{$catalogue}", []))
            ->unique('code')
            ->values()
            ->all();
    }
}
