<?php

namespace App\Shared\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Notification\Repositories\PreferenceRepository;
use App\Shared\Notification\Requests\UpdatePreferenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function __construct(
        protected PreferenceRepository $repository
    ) {}

    public function show(Request $request): JsonResponse
    {
        $preference = $this->repository->getForUser($request->user(), $request->input('clinic_id'));

        return response()->json($preference);
    }

    public function update(UpdatePreferenceRequest $request): JsonResponse
    {
        $preference = $this->repository->updatePreferences(
            $request->user(),
            $request->validated('clinic_id'),
            $request->validated()
        );

        return response()->json($preference);
    }
}
