<?php

namespace App\Services\Reception;

use App\Models\Clinic;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * One result per request key (`intake_submissions`): registrations,
 * check-ins, and the money requests (payments, refunds, desk collections,
 * package sales).
 *
 * The screen sends a key made when the dialog or form opened. The key is
 * claimed before any work starts, so a second click, an Enter pressed twice
 * or a retried request either waits out the first ("still saving") or gets
 * its result back — it never registers, queues or charges twice. A request
 * that fails (validation, a duplicate warning, a refused payment) frees its
 * key so the same dialog can try again.
 *
 * A key belongs to one clinic, one user and one action. `$action` names the
 * operation and the record it is for (`payment:12`), so a key reused on
 * another bill, by another user or in another clinic never replays this
 * result: in this clinic it is refused, elsewhere it is simply a new key.
 */
class IntakeSubmissions
{
    /** The `intake_submissions.action` column's width. */
    private const ACTION_LENGTH = 20;

    /**
     * `$atomic` is for money: the work and the key's result are saved in one
     * transaction, so once the payment (refund, package) is saved its key is
     * never freed — not even when something after the commit (the patient
     * message the payment triggers) fails. The retry then gets the saved
     * result, not a second payment.
     *
     * @param  Closure(): JsonResponse  $handler
     */
    public function once(Clinic $clinic, User $user, ?string $key, string $action, Closure $handler, bool $atomic = false): JsonResponse
    {
        $this->assertAction($action);

        if ($key === null || $key === '') {
            return $handler();
        }

        $row = $this->find($clinic, $key);
        if ($row) {
            return $this->replay($row, $user, $action);
        }

        try {
            $id = DB::table('intake_submissions')->insertGetId([
                'clinic_id' => $clinic->id, 'user_id' => $user->id, 'idempotency_key' => $key, 'action' => $action,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($this->find($clinic, $key), $user, $action);
        }

        $complete = function (JsonResponse $response) use ($id): void {
            if ($response->getStatusCode() < 300) {
                DB::table('intake_submissions')->where('id', $id)->update(['status' => $response->getStatusCode(), 'response' => $response->getContent(), 'updated_at' => now()]);
            }
        };

        try {
            $response = $atomic
                ? DB::transaction(function () use ($handler, $complete) {
                    $response = $handler();
                    $complete($response);

                    return $response;
                })
                : $handler();
        } catch (Throwable $e) {
            $this->release($id);
            throw $e;
        }

        if ($response->getStatusCode() >= 300) {
            $this->release($id);
        } elseif (! $atomic) {
            $complete($response);
        }

        return $response;
    }

    /**
     * The same guarantee for the full-page form, which answers with
     * redirects: a repeated submit is sent where the first one went, with a
     * note that the patient is already registered. `$succeeded` says whether
     * the handler did the work; any other answer (validation, a duplicate
     * warning) frees the key so the same form can try again.
     *
     * `$replayed` is the note shown on a repeated submit; `$atomic` is as
     * for `once()`.
     *
     * @param  Closure(): RedirectResponse  $handler
     * @param  Closure(): bool  $succeeded
     */
    public function onceRedirect(Clinic $clinic, User $user, string $key, string $action, Closure $handler, Closure $succeeded, string $replayed = 'This patient is already registered; the form was not saved twice.', bool $atomic = false): RedirectResponse
    {
        $this->assertAction($action);

        $row = $this->find($clinic, $key);
        if ($row) {
            return $this->replayRedirect($row, $user, $action, $replayed);
        }

        try {
            $id = DB::table('intake_submissions')->insertGetId([
                'clinic_id' => $clinic->id, 'user_id' => $user->id, 'idempotency_key' => $key, 'action' => $action,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replayRedirect($this->find($clinic, $key), $user, $action, $replayed);
        }

        $complete = function (RedirectResponse $response) use ($id, $succeeded): void {
            if ($succeeded()) {
                DB::table('intake_submissions')->where('id', $id)->update([
                    'status' => $response->getStatusCode(),
                    'response' => json_encode(['redirect' => $response->getTargetUrl()]),
                    'updated_at' => now(),
                ]);
            }
        };

        try {
            $response = $atomic
                ? DB::transaction(function () use ($handler, $complete) {
                    $response = $handler();
                    $complete($response);

                    return $response;
                })
                : $handler();
        } catch (Throwable $e) {
            $this->release($id);
            throw $e;
        }

        if (! $succeeded()) {
            $this->release($id);
        } elseif (! $atomic) {
            $complete($response);
        }

        return $response;
    }

    /**
     * Free a key whose work did not happen. A claim that already holds a
     * result is kept: its work was saved, whatever failed after it.
     */
    private function release(int $id): void
    {
        DB::table('intake_submissions')->where('id', $id)->whereNull('status')->delete();
    }

    /**
     * A money request that came without a key, while keys are still optional:
     * it runs unprotected, and this records which entry point is still called
     * that way. Only the entry point and the clinic are logged — never the
     * amount, the patient or anything the request carried.
     */
    public function withoutKey(Clinic $clinic, string $entryPoint): void
    {
        Log::warning('Request without an idempotency key: it is not protected against a repeat.', [
            'entry_point' => $entryPoint,
            'clinic_id' => $clinic->id,
        ]);
    }

    /** An action longer than its column would be cut short or refused by the database. */
    private function assertAction(string $action): void
    {
        if (mb_strlen($action) > self::ACTION_LENGTH) {
            throw new LogicException("Request action \"{$action}\" is longer than ".self::ACTION_LENGTH.' characters.');
        }
    }

    private function replayRedirect(?object $row, User $user, string $action, string $replayed): RedirectResponse
    {
        if (! $row || (int) $row->user_id !== $user->id || $row->action !== $action) {
            return back()->withInput()->withErrors(['idempotency_key' => 'This form was already submitted. Reload the page and start again.']);
        }
        if ($row->status === null) {
            return back()->withInput()->withErrors(['idempotency_key' => 'This is still being saved. Wait a moment.']);
        }

        $target = json_decode((string) $row->response, true)['redirect'] ?? null;

        return redirect()->to(is_string($target) ? $target : route('clinic.patients.index'))
            ->with('success', $replayed);
    }

    private function find(Clinic $clinic, string $key): ?object
    {
        return DB::table('intake_submissions')->where('clinic_id', $clinic->id)->where('idempotency_key', $key)->first();
    }

    private function replay(?object $row, User $user, string $action): JsonResponse
    {
        if (! $row || (int) $row->user_id !== $user->id || $row->action !== $action) {
            return response()->json(['message' => 'This request was already used. Close the dialog and start again.'], 409);
        }
        if ($row->status === null) {
            return response()->json(['message' => 'This is still being saved. Wait a moment.'], 409);
        }

        return response()->json([...json_decode((string) $row->response, true), 'replayed' => true], (int) $row->status);
    }
}
