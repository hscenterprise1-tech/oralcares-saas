<?php

namespace App\Services\Security;

use App\Http\Middleware\AssignRequestId;
use App\Models\SecurityAuditLog;
use App\Services\ClinicContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records security-relevant events. Each event goes to two places:
 *
 *   1. `security_audit_logs`, append-only in the app and hash-chained, so a
 *      direct database edit, delete or reorder is detectable
 *      (`security:verify-audit-chain`);
 *   2. the `security` log channel, which production ships off the server,
 *      so the trail survives a compromise of this server or its database.
 *
 * The actor, request address, user agent, request id, clinic and branch are
 * filled in from the current request and `ClinicContext`. Callers pass them
 * only when they know better, as a sign-in does before any clinic context
 * exists.
 *
 * Context never carries secrets. Any key that looks like a password, token or
 * secret is dropped at any depth before either copy is written.
 *
 * A failure to write the database copy is logged and does not break the
 * action being audited: refusing a clinician's sign-in because the audit table
 * was briefly unavailable would be the wrong trade. The log-channel copy is
 * still attempted.
 */
class SecurityAudit
{
    /** Keys removed from context at any depth, matched case-insensitively. */
    private const SECRET_KEY_PATTERN = '/pass(word)?|token|secret|otp|pin|hash|key|credential|authorization|cookie/i';

    /**
     * @param  array<string, mixed>  $context
     * @param  array{actor_user_id?: int|null, clinic_id?: int|null, branch_id?: int|null}  $overrides
     */
    public function record(string $event, ?Model $subject = null, array $context = [], array $overrides = []): void
    {
        $request = request();
        $clinicContext = app()->bound(ClinicContext::class) ? app(ClinicContext::class) : null;

        $entry = [
            'event' => $event,
            'actor_user_id' => array_key_exists('actor_user_id', $overrides) ? $overrides['actor_user_id'] : $request->user()?->getAuthIdentifier(),
            'clinic_id' => array_key_exists('clinic_id', $overrides) ? $overrides['clinic_id'] : ($clinicContext?->hasClinic() ? $clinicContext->clinic()->id : null),
            'branch_id' => array_key_exists('branch_id', $overrides) ? $overrides['branch_id'] : $clinicContext?->branch()?->id,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'request_id' => $request->attributes->get(AssignRequestId::ATTRIBUTE),
            'context' => $context === [] ? null : $this->withoutSecrets($context),
            'occurred_at' => now()->toImmutable()->startOfSecond(),
        ];

        $entryHash = null;

        try {
            $entryHash = DB::transaction(function () use ($entry) {
                // Serialize chain appends: the newest entry's hash is the next
                // entry's `prev_hash`.
                $previous = SecurityAuditLog::query()->latest('id')->lockForUpdate()->value('entry_hash');
                $hash = $this->hashFor($entry, $previous);

                SecurityAuditLog::create($entry + ['prev_hash' => $previous, 'entry_hash' => $hash]);

                return $hash;
            });
        } catch (Throwable $e) {
            Log::error('Security audit entry could not be written to the database.', ['event' => $event, 'error' => $e->getMessage()]);
        }

        try {
            Log::channel(config('security.audit.log_channel', 'security'))->info($event, array_merge($entry, [
                'occurred_at' => $entry['occurred_at']->toIso8601String(),
                'entry_hash' => $entryHash,
            ]));
        } catch (Throwable $e) {
            Log::error('Security audit entry could not be shipped to the security log channel.', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The HMAC chaining an entry to its predecessor. The key is kept apart from
     * the database, so someone who can only write rows cannot forge a valid
     * chain.
     *
     * @param  array<string, mixed>  $entry
     */
    public function hashFor(array $entry, ?string $previousHash): string
    {
        $occurredAt = $entry['occurred_at'] instanceof \DateTimeInterface
            ? $entry['occurred_at']->format('Y-m-d\TH:i:sP')
            : (string) $entry['occurred_at'];

        $canonical = json_encode([
            $entry['event'],
            $entry['actor_user_id'] === null ? null : (int) $entry['actor_user_id'],
            $entry['clinic_id'] === null ? null : (int) $entry['clinic_id'],
            $entry['branch_id'] === null ? null : (int) $entry['branch_id'],
            $entry['subject_type'],
            $entry['subject_id'] === null ? null : (string) $entry['subject_id'],
            $entry['ip_address'],
            $entry['user_agent'],
            $entry['request_id'],
            // Key order is normalised: MySQL's JSON type reorders object keys
            // on storage, which would otherwise break verification.
            $this->canonical($entry['context']),
            $occurredAt,
            $previousHash,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', (string) $canonical, $this->chainKey());
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }

    private function chainKey(): string
    {
        return (string) (config('security.audit.chain_key') ?: config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withoutSecrets(array $context): array
    {
        return Arr::map(
            Arr::where($context, fn ($value, $key) => ! is_string($key) || ! preg_match(self::SECRET_KEY_PATTERN, $key)),
            fn ($value) => is_array($value) ? $this->withoutSecrets($value) : $value
        );
    }
}
