<?php

namespace App\Http\Controllers\Company\Crm;

use App\ActivityType;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lead-scoped activity writer — the path used by the Lead Detail timeline.
 */
class LeadActivityController extends Controller
{
    public function store(Request $request, Lead $lead)
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'description' => 'required|string|max:1000',
            'type' => 'required|string',
            'properties' => 'nullable|array',
        ]);

        $type = ActivityType::tryFrom($validated['type']) ?? ActivityType::Note;

        $lead->activities()->create([
            'type' => $type,
            'description' => $validated['description'],
            'properties' => $validated['properties'] ?? [],
            'user_id' => auth()->id(), // Activity has user_id, not performed_by
        ]);

        // Touch the lead to trigger the saving event for score and NBA calculation
        $lead->touch();

        return back()->with('success', 'Activity logged successfully.');
    }
}
