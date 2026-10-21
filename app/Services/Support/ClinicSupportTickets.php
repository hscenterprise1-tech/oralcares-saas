<?php

namespace App\Services\Support;

use App\Models\Clinic;
use App\Models\CompanySupportTicket;
use App\Models\User;
use App\Support\ClinicAccess\ClinicAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which support requests a clinic member may see: the one rule behind the
 * Support pages and the portal's search, so the two never disagree.
 *
 * Only the clinic of the session. Every member sees the requests they
 * raised; the owner and whoever manages the clinic's settings see all of the
 * clinic's.
 */
class ClinicSupportTickets
{
    public function __construct(protected ClinicAccess $access) {}

    public function seesAll(): bool
    {
        return $this->access->isOwner() || $this->access->allows(['permissions' => ['settings.manage']]);
    }

    /** @return Builder<CompanySupportTicket> */
    public function visible(Clinic $clinic, User $user): Builder
    {
        return CompanySupportTicket::query()
            ->where('clinic_id', $clinic->id)
            ->when(! $this->seesAll(), fn (Builder $query) => $query->where('opened_by', $user->id));
    }
}
