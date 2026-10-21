<?php

namespace App\Services\Onboarding\Sections\Staff;

use App\Models\ClinicalProcedure;
use App\Services\Onboarding\OnboardingContext;
use App\Services\Onboarding\Sections\AbstractSection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Which of the clinic's services this practitioner provides. The booking
 * engine only offers them for these services.
 */
class MyServicesSection extends AbstractSection
{
    public function key(): string
    {
        return 'my_services';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Services you provide';
    }

    public function description(OnboardingContext $context): string
    {
        return 'Patients are only booked with you for these services.';
    }

    public function initial(OnboardingContext $context): array
    {
        return ['service_ids' => $context->employee?->services()->pluck('clinical_procedures.id')->map(fn ($id) => (int) $id)->all() ?? []];
    }

    public function meta(OnboardingContext $context): array
    {
        return [
            'services' => $this->catalogue($context)->map(fn (ClinicalProcedure $service) => ['value' => $service->id, 'label' => $service->name, 'category' => $service->category])->values()->all(),
        ];
    }

    public function rules(OnboardingContext $context): array
    {
        return [
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'distinct', Rule::in($this->catalogue($context)->pluck('id')->all())],
        ];
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        $context->employee->services()->sync(
            collect($data['service_ids'])->mapWithKeys(fn ($id) => [(int) $id => ['clinic_id' => $context->clinic->id]])->all()
        );
    }

    public function summary(OnboardingContext $context): array
    {
        $names = $context->employee?->services()->pluck('clinical_procedures.name') ?? collect();

        return [$names->count().' services: '.$names->take(4)->implode(', ')];
    }

    /**
     * @return Collection<int, ClinicalProcedure>
     */
    private function catalogue(OnboardingContext $context)
    {
        return ClinicalProcedure::withoutGlobalScopes()
            ->where('clinic_id', $context->clinic->id)
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'category']);
    }
}
