<?php

namespace App\Http\Controllers\Clinic\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ClinicRoom;
use App\Services\ClinicContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ClinicContext $context): Response
    {
        $clinicId = $context->clinic()->id;

        $branches = Branch::where('clinic_id', $clinicId)->orderBy('name')->get(['id', 'name']);

        $rooms = ClinicRoom::whereIn('branch_id', $branches->pluck('id'))
            ->with('branch:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (ClinicRoom $room) => [
                'id' => $room->id,
                'branch_id' => $room->branch_id,
                'branch_name' => $room->branch?->name,
                'name' => $room->name,
                'key' => $room->key,
                'type' => $room->type,
                'is_active' => $room->is_active,
            ]);

        return Inertia::render('Clinic/Admin/Infrastructure/Rooms', [
            'rooms' => $rooms,
            'branches' => $branches,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, ClinicContext $context): RedirectResponse
    {
        $clinicId = $context->clinic()->id;
        $branchIds = Branch::where('clinic_id', $clinicId)->pluck('id')->toArray();

        $validated = $request->validate([
            'branch_id' => ['required', Rule::in($branchIds)],
            'name' => ['required', 'string', 'max:255'],
            'key' => [
                'required',
                'string',
                'max:50',
                Rule::unique('clinic_rooms')->where(function ($query) use ($request) {
                    return $query->where('branch_id', $request->input('branch_id'));
                }),
            ],
            'type' => ['required', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        ClinicRoom::create($validated);

        return redirect()->route('admin.rooms.index')->with('success', 'Room created successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ClinicRoom $room, ClinicContext $context): RedirectResponse
    {
        $clinicId = $context->clinic()->id;
        $branchIds = Branch::where('clinic_id', $clinicId)->pluck('id')->toArray();

        // Ensure the room belongs to a valid branch
        if (! in_array($room->branch_id, $branchIds)) {
            abort(403);
        }

        $validated = $request->validate([
            'branch_id' => ['required', Rule::in($branchIds)],
            'name' => ['required', 'string', 'max:255'],
            'key' => [
                'required',
                'string',
                'max:50',
                Rule::unique('clinic_rooms')->ignore($room->id)->where(function ($query) use ($request) {
                    return $query->where('branch_id', $request->input('branch_id'));
                }),
            ],
            'type' => ['required', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        $room->update($validated);

        return redirect()->route('admin.rooms.index')->with('success', 'Room updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ClinicRoom $room, ClinicContext $context): RedirectResponse
    {
        $clinicId = $context->clinic()->id;
        $branchIds = Branch::where('clinic_id', $clinicId)->pluck('id')->toArray();

        if (! in_array($room->branch_id, $branchIds)) {
            abort(403);
        }

        $room->delete();

        return redirect()->route('admin.rooms.index')->with('success', 'Room deleted successfully.');
    }
}
