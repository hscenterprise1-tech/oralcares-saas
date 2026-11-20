<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\Http\Controllers\Concerns\LinksSettingsPages;
use App\Http\Controllers\Controller;
use App\Models\ClinicConsentType;
use App\Models\ConsentRecord;
use App\Services\ClinicContext;
use App\Services\Patient\ConsentTypes;
use App\Support\Specialty\SpecialtyRegistry;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration → Consents: the consents the clinic asks for and their
 * wording. A changed wording is a new version; earlier versions, and the
 * consents signed against them, are never altered. A specialty's consents
 * appear only while the clinic has that specialty.
 */
class ConsentTypeController extends Controller
{
    use LinksSettingsPages;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected ConsentTypes $types,
        protected SpecialtyRegistry $specialties,
    ) {}

    public function index(): Response
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $specialties = $this->specialties->forClinic($clinic);
        $given = ConsentRecord::query()->where('clinic_id', $clinic->id)->toBase()->selectRaw('consent_type, count(*) as total')->groupBy('consent_type')->pluck('total', 'consent_type');

        return Inertia::render('ClinicPortal/Admin/Consents', [
            'settings_links' => $this->settingsLinks($clinic),
            'links' => ['base' => route('admin.consents.index')],
            'types' => $this->types->all($clinic)
                ->filter(fn (ClinicConsentType $type) => $type->specialty_key === null || in_array($type->specialty_key, $specialties, true))
                ->map(fn (ClinicConsentType $type) => [
                    'id' => $type->id,
                    'key' => $type->key,
                    'label' => $type->label,
                    'specialty' => $type->specialty_key ? ($this->specialties->get($type->specialty_key)['label'] ?? $type->specialty_key) : null,
                    'version' => $type->current_version,
                    'text' => $this->types->current($type)?->text,
                    'active' => $type->is_active,
                    'given' => (int) ($given[$type->key] ?? 0),
                ])->values(),
            'specialties' => collect($specialties)->map(fn (string $key) => ['key' => $key, 'label' => $this->specialties->get($key)['label'] ?? $key])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'text' => ['required', 'string', 'max:20000'],
            'specialty' => ['nullable', 'string', Rule::in($this->specialties->forClinic($this->clinicContext->clinic()))],
        ]);

        return $this->attempt(fn () => $this->types->add($this->clinicContext->clinic(), $data['label'], $data['text'], $data['specialty'] ?? null, $request->user()), 'Consent added.');
    }

    public function update(Request $request, int $type): RedirectResponse
    {
        $record = $this->find($type);
        $data = $request->validate(['label' => ['required', 'string', 'max:120'], 'text' => ['required', 'string', 'max:20000']]);

        return $this->attempt(fn () => $this->types->update($record, $data['label'], $data['text'], $request->user()), 'Consent saved. A changed wording is a new version; earlier consents keep theirs.');
    }

    public function setActive(Request $request, int $type): RedirectResponse
    {
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];
        $this->types->setActive($this->find($type), (bool) $active, $request->user());

        return back()->with('success', $active ? 'Consent switched on.' : 'Consent switched off. Consents already given are kept.');
    }

    private function find(int $id): ClinicConsentType
    {
        return ClinicConsentType::query()->where('clinic_id', $this->clinicContext->clinic()?->id)->findOrFail($id);
    }

    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->withErrors(['consent' => $e->getMessage()]);
        }

        return back()->with('success', $success);
    }
}
