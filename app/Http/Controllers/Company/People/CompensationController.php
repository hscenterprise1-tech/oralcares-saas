<?php

namespace App\Http\Controllers\Company\People;

use App\Http\Controllers\Controller;
use App\Models\CompanyEmployee;
use App\Models\CompensationComponent;
use App\Models\EmployeeCompensation;
use App\Services\People\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What one employee earns, and what they used to earn.
 *
 * Compensation belongs to the person, so it lives on the employee rather than
 * in a payroll settings screen. Recording a new arrangement closes the previous
 * one by date instead of editing it, which is what lets a run calculated last
 * month keep reporting last month's figures.
 */
class CompensationController extends Controller
{
    public function __construct(protected PayrollService $payroll) {}

    public function index(Request $request, CompanyEmployee $employee): Response
    {
        Gate::authorize('view-payroll');

        $employee->load('user:id,name,email');

        $history = EmployeeCompensation::query()
            ->with(['lines.component', 'creator:id,name'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeCompensation $compensation) => [
                'id' => $compensation->id,
                'base_salary' => (float) $compensation->base_salary,
                'effective_from' => $compensation->effective_from?->toDateString(),
                'effective_to' => $compensation->effective_to?->toDateString(),
                'is_current' => $compensation->effective_to === null,
                'notes' => $compensation->notes,
                'created_by' => $compensation->creator?->name,
                'lines' => $compensation->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'component_name' => $line->component?->name,
                    'component_code' => $line->component?->code,
                    'type' => $line->component?->type?->value,
                    'calculation_type' => $line->component?->calculation_type?->value,
                    'value' => (float) $line->value,
                ])->values(),
            ])
            ->values();

        return Inertia::render('Company/People/CompensationHistory', [
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->user?->name ?? $employee->employee_code,
                'designation' => $employee->designation,
            ],
            'history' => $history,
            'components' => CompensationComponent::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (CompensationComponent $component) => [
                    'id' => $component->id,
                    'code' => $component->code,
                    'name' => $component->name,
                    'type' => $component->type->value,
                    'calculation_type' => $component->calculation_type->value,
                ])
                ->values(),
            'can' => ['manage' => Gate::allows('manage-payroll')],
        ]);
    }

    public function store(Request $request, CompanyEmployee $employee)
    {
        Gate::authorize('manage-payroll');

        $validated = $request->validate([
            'base_salary' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['array'],
            'lines.*.component_id' => ['required', 'exists:company_compensation_components,id'],
            'lines.*.value' => ['required', 'numeric', 'min:0'],
        ]);

        $this->payroll->setCompensation(
            $employee,
            $validated,
            $validated['lines'] ?? [],
            $request->user(),
        );

        return back()->with('success', 'Compensation recorded. The previous arrangement was closed, not overwritten.');
    }
}
