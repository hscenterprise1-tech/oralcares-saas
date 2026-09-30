<?php

namespace App\Console\Commands;

use App\Models\SecurityAuditLog;
use App\Services\Security\SecurityAudit;
use Illuminate\Console\Command;

/**
 * Walks `security_audit_logs` in order and recomputes every entry's HMAC from
 * its content and its predecessor's hash. A row edited, deleted or inserted
 * out of band breaks the chain at that point, and the command reports the
 * first entry that no longer verifies. Rows written before the chain existed
 * (no `entry_hash`) are reported as unchained, not as tampered.
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'security:verify-audit-chain';

    protected $description = 'Verify the tamper-evident hash chain of the security audit trail';

    public function handle(SecurityAudit $audit): int
    {
        $previous = null;
        $checked = 0;
        $unchained = 0;

        foreach (SecurityAuditLog::query()->orderBy('id')->lazyById(500) as $row) {
            if ($row->entry_hash === null) {
                $unchained++;

                continue;
            }

            $entry = [
                'event' => $row->event,
                'actor_user_id' => $row->actor_user_id,
                'clinic_id' => $row->clinic_id,
                'branch_id' => $row->branch_id,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'request_id' => $row->request_id,
                'context' => $row->context,
                'occurred_at' => $row->occurred_at,
            ];

            if ($row->prev_hash !== $previous || ! hash_equals($audit->hashFor($entry, $previous), $row->entry_hash)) {
                $this->error("Chain broken at security_audit_logs.id = {$row->id} ({$row->event}). Entries from here on cannot be trusted.");

                return self::FAILURE;
            }

            $previous = $row->entry_hash;
            $checked++;
        }

        $this->info("Audit chain intact: {$checked} entries verified".($unchained ? ", {$unchained} older unchained entries skipped" : '').'.');

        return self::SUCCESS;
    }
}
