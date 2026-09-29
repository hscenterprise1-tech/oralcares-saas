<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A queue move the state machine does not permit — completing someone who was
 * never seen, calling someone who already left. Same shape as
 * `IllegalStageTransition`: it renders itself as a validation error on
 * `status`, so the Live Queue, the Now/Next card and any job all get the same
 * sentence without catching it themselves.
 */
class IllegalQueueTransition extends DomainException
{
    public function __construct(
        public readonly string $from,
        public readonly string $action,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): never
    {
        throw ValidationException::withMessages(['status' => $this->getMessage()]);
    }
}
