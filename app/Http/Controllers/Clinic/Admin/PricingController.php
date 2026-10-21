<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\Http\Controllers\Concerns\AnswersDomainErrors;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CatalogPriceVersion;
use App\Services\ClinicContext;
use App\Services\Treatment\CatalogPricing;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pricing API (T5): one target's price history, and a new price version.
 * A price is never edited — `CatalogPricing::change()` closes the open
 * version and opens the next, authorised and audited there.
 */
class PricingController extends Controller
{
    use AnswersDomainErrors;

    public function __construct(
        protected ClinicContext $clinicContext,
        protected CatalogPricing $pricing,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target' => ['required', Rule::in(CatalogPriceVersion::TARGETS)],
            'target_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $this->pricing->history($this->clinicContext->clinic()->id, $data['target'], (int) $data['target_id'])
            ->map(fn (CatalogPriceVersion $p) => [
                'id' => $p->id, 'branch_id' => $p->branch_id, 'amount' => $p->amount, 'currency' => $p->currency,
                'effective_from' => $p->effective_from?->toIso8601String(), 'effective_to' => $p->effective_to?->toIso8601String(),
                'created_by' => $p->created_by,
            ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $clinic = $this->clinicContext->clinic();
        $data = $request->validate([
            'target' => ['required', Rule::in(CatalogPriceVersion::TARGETS)],
            'target_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('clinic_id', $clinic->id)],
            'effective_from' => ['nullable', 'date'],
        ]);
        $branch = isset($data['branch_id']) ? Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->findOrFail($data['branch_id']) : null;
        $from = isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from']) : null;

        return $this->attemptJson(fn () => ['data' => $this->pricing->change($clinic, $data['target'], (int) $data['target_id'], (float) $data['amount'], $branch, $request->user(), $from)
            ->only(['id', 'branch_id', 'amount', 'effective_from', 'effective_to'])], 201);
    }
}
