<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A stage move the pipeline does not permit.
 *
 * Distinct from a missing prerequisite. A prerequisite is something the user can
 * go and satisfy — book the demo, record the payment — and the answer is "not
 * yet, here is what to do". This is the other kind: the edge does not exist in
 * the state machine at all, and no amount of data will make it exist. A won deal
 * is an active customer; there is nothing a rep can attach to it that makes
 * dragging it back to Contacted correct.
 *
 * It renders itself as a validation error on the `stage` field so that every
 * caller — the deal page's stepper, the bulk mover, an API client, a job —
 * gets the same 422 with the same sentence, without each one having to catch it.
 */
class IllegalStageTransition extends DomainException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): never
    {
        throw ValidationException::withMessages(['stage' => $this->getMessage()]);
    }
}
