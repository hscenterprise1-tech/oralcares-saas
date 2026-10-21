<?php

namespace App\Services\Onboarding;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An idempotency key reused for a different payload. Answered with 409 for
 * JSON clients and a validation-style error for Inertia forms.
 */
class OnboardingConflictException extends RuntimeException
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withErrors(['step' => $this->getMessage()]);
    }
}
