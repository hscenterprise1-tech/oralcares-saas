<?php

namespace App\Services\Onboarding\Sections\Staff;

use App\Services\Onboarding\OnboardingContext;
use App\Services\Onboarding\Sections\AbstractSection;
use App\Services\Staff\StaffDocumentChecklist;
use App\Services\Staff\StaffDocumentService;

/**
 * Upload the documents the clinic's checklist asks for. Files go through
 * the private, malware-scanned staff document store (a separate upload
 * request per file); this step only confirms the list. Verification is
 * done by the clinic afterwards.
 */
class MyDocumentsSection extends AbstractSection
{
    public function __construct(protected StaffDocumentChecklist $checklist) {}

    public function key(): string
    {
        return 'my_documents';
    }

    public function title(OnboardingContext $context): string
    {
        return 'Your documents';
    }

    public function description(OnboardingContext $context): string
    {
        return 'Upload what your clinic asks for. The clinic verifies each one.';
    }

    public function required(OnboardingContext $context): bool
    {
        return false;
    }

    public function initial(OnboardingContext $context): array
    {
        return ['confirmed' => true];
    }

    public function meta(OnboardingContext $context): array
    {
        $file = $this->checklist->forEmployee($context->clinic, $context->employee);

        return [
            'items' => collect($file['items'])->map(fn (array $item) => [
                'type_id' => $item['type']['id'],
                'name' => $item['type']['name'],
                'has_expiry' => $item['type']['has_expiry'],
                'requirement' => $item['requirement'],
                'state' => $item['state'],
                'file_name' => $item['current']['file_name'] ?? null,
            ])->values()->all(),
            'summary' => $file['summary'],
            'upload_url' => route('clinic.onboarding.documents.store'),
            'max_kb' => StaffDocumentService::MAX_KB,
            'accept' => '.'.implode(',.', StaffDocumentService::MIMES),
        ];
    }

    public function rules(OnboardingContext $context): array
    {
        return ['confirmed' => ['accepted']];
    }

    public function apply(OnboardingContext $context, array $data): void {}

    public function summary(OnboardingContext $context): array
    {
        $summary = $this->checklist->forEmployee($context->clinic, $context->employee)['summary'];

        return ["{$summary['complete']} of {$summary['required']} required documents verified"];
    }
}
