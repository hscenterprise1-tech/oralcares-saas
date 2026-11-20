<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Services\Billing\DayClosing;
use App\Services\ClinicContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Closing the day at the desk: the ledger's figures for a day at the working
 * branch, and the desk's count of the cash against them. For whoever takes
 * payments (`payment.collect`, on the route); the branch is the member's own.
 */
class DayClosingController extends Controller
{
    public function __construct(protected ClinicContext $clinicContext, protected DayClosing $closing) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->closing->summary($this->clinicContext->clinic() ?? abort(403), $this->clinicContext->requireBranch(), $data['date'] ?? null));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $branch = $this->clinicContext->requireBranch();

        try {
            $closed = $this->closing->close($clinic, $branch, $data['date'], (float) $data['counted_cash'], $data['notes'] ?? null, $this->clinicContext->currentEmployee(), $request->user());
        } catch (DomainException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['counted_cash' => [$e->getMessage()]]], 422);
        }

        return response()->json(['message' => 'Day closed.', 'closing' => $closed, 'summary' => $this->closing->summary($clinic, $branch, $data['date'])], 201);
    }
}
