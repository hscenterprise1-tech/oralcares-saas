<?php

namespace App\Http\Controllers\Concerns;

use Closure;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * JSON endpoints answer a refused domain action (a closed stage, an
 * unpriced charge, an immutable record) with 422 and its message, not 500.
 * Authorization failures stay 403 (AuthorizationException is not caught).
 */
trait AnswersDomainErrors
{
    /**
     * @param  Closure(): mixed  $action
     */
    protected function attemptJson(Closure $action, int $status = 200): JsonResponse
    {
        try {
            $result = $action();
        } catch (LogicException $e) {
            // DomainException, InvalidArgumentException and the models' immutability guards.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $result instanceof JsonResponse ? $result : response()->json($result, $status);
    }
}
