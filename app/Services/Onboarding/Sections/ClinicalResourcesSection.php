<?php

namespace App\Services\Onboarding\Sections;

use App\Models\ClinicRoom;
use App\Services\Onboarding\OnboardingContext;

/**
 * How many treatment resources the primary branch has: dental chairs,
 * physiotherapy beds, consultation rooms. Only templates that declare a
 * resource include this section. Extra resources are switched off, never
 * deleted, so appointments that used them keep their history.
 */
class ClinicalResourcesSection extends AbstractSection
{
    public const MAX = 50;

    public function key(): string
    {
        return 'clinical_resources';
    }

    public function title(OnboardingContext $context): string
    {
        return $context->option('resource.label', 'Treatment rooms');
    }

    public function description(OnboardingContext $context): string
    {
        $label = strtolower($context->option('resource.label', 'treatment rooms'));

        return "How many {$label} the primary branch has. Each one can hold one appointment at a time.";
    }

    public function initial(OnboardingContext $context): array
    {
        $count = $this->active($context);

        return ['count' => $count ?: (int) $context->option('resource.default', 1)];
    }

    public function meta(OnboardingContext $context): array
    {
        return [
            'label' => $context->option('resource.label', 'Treatment rooms'),
            'singular' => $context->option('resource.singular', 'Room'),
            'max' => self::MAX,
        ];
    }

    public function rules(OnboardingContext $context): array
    {
        return ['count' => ['required', 'integer', 'min:1', 'max:'.self::MAX]];
    }

    public function apply(OnboardingContext $context, array $data): void
    {
        $type = $context->option('resource.type', 'consultation');
        $singular = $context->option('resource.singular', 'Room');
        $count = (int) $data['count'];

        for ($number = 1; $number <= $count; $number++) {
            ClinicRoom::updateOrCreate(
                ['branch_id' => $context->branch->id, 'key' => "{$type}-{$number}"],
                ['name' => "{$singular} {$number}", 'type' => $type, 'is_active' => true],
            );
        }

        ClinicRoom::query()
            ->where('branch_id', $context->branch->id)
            ->where('type', $type)
            ->where('key', 'like', "{$type}-%")
            ->get()
            ->filter(fn (ClinicRoom $room) => (int) substr($room->key, strlen($type) + 1) > $count)
            ->each(fn (ClinicRoom $room) => $room->update(['is_active' => false]));
    }

    public function summary(OnboardingContext $context): array
    {
        return [$this->active($context).' '.strtolower($context->option('resource.label', 'rooms'))];
    }

    private function active(OnboardingContext $context): int
    {
        return $context->branch
            ? ClinicRoom::query()->where('branch_id', $context->branch->id)->where('type', $context->option('resource.type', 'consultation'))->where('is_active', true)->count()
            : 0;
    }
}
