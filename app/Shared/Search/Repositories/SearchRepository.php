<?php

namespace App\Shared\Search\Repositories;

use App\ClinicPermission;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\User;
use App\Services\ClinicContext;
use App\Shared\Search\Models\SearchDocument;
use App\UserType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SearchRepository
{
    /**
     * Index document types that hold a clinic's own records, with the clinic
     * permission needed to look each one up. A clinic user finds only these,
     * in their active clinic; HQ never finds them.
     *
     * @var array<class-string, ClinicPermission>
     */
    public const CLINIC_DOCUMENT_TYPES = [
        Patient::class => ClinicPermission::PatientSearch,
        PatientBilling::class => ClinicPermission::BillingView,
    ];

    /**
     * Perform a global search across indexed documents.
     */
    public function search(
        User $user,
        string $query,
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {
        $q = SearchDocument::query();

        /*
         * 1. Tenant scoping.
         *
         * A clinic user searches the clinic they are working in — the active
         * `ClinicContext` clinic — and only the clinic's own records they hold
         * the permission to look up. It used to be every clinic of
         * `users.organization_id`, which let a receptionist of one clinic find
         * a sister clinic's patients without a membership there, and scoped a
         * login that belongs to clinics of two organisations to whichever one
         * happened to be stamped on the user row.
         *
         * HQ (company users and master admins) sees the head-office index but
         * never a clinic's clinical records: OralCares sees whether a tenant
         * uses the product, not its patients.
         */
        if ($this->isTenantUser($user)) {
            $clinic = app(ClinicContext::class)->clinic();
            $types = $clinic ? $this->clinicTypesFor($user) : [];

            if ($types === []) {
                $q->whereRaw('1 = 0');
            } else {
                $q->where('clinic_id', $clinic->id)->whereIn('model_type', $types);
            }
        } else {
            $q->whereNotIn('model_type', array_keys(self::CLINIC_DOCUMENT_TYPES));
        }

        // 2. Search Query Matching (MySQL FULLTEXT or SQLite LIKE fallback)
        $booleanQuery = $this->booleanQuery($query);

        if (! empty($query)) {
            if (DB::getDriverName() === 'mysql') {
                $booleanQuery === ''
                    ? $q->whereRaw('1 = 0')
                    : $q->whereRaw('MATCH(title, subtitle, searchable_text) AGAINST(? IN BOOLEAN MODE)', [$booleanQuery]);
            } else {
                $q->where(function ($sub) use ($query) {
                    $sub->where('title', 'like', "%{$query}%")
                        ->orWhere('subtitle', 'like', "%{$query}%")
                        ->orWhere('searchable_text', 'like', "%{$query}%");
                });
            }
        }

        // 3. Apply Filters
        if (! empty($filters['module'])) {
            $q->where('module', $filters['module']);
        }

        if (! empty($filters['status'])) {
            $q->whereJsonContains('metadata->status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $q->whereJsonContains('metadata->priority', $filters['priority']);
        }

        // 4. Sort and Paginate
        if ($booleanQuery !== '' && DB::getDriverName() === 'mysql') {
            $q->orderByRaw('MATCH(title, subtitle, searchable_text) AGAINST(? IN BOOLEAN MODE) DESC', [$booleanQuery]);
        } else {
            $q->latest('updated_at');
        }

        return $q->paginate($perPage);
    }

    /**
     * The search text as a FULLTEXT boolean-mode query: each word a prefix
     * match, with the boolean operators removed so typed input (an email's
     * `@`, a stray quote) can never become query syntax or a syntax error.
     */
    protected function booleanQuery(string $query): string
    {
        return collect(preg_split('/\s+/', trim($query)) ?: [])
            ->map(fn (string $word) => preg_replace('/[+\-><()~*"@]/', '', $word))
            ->filter(fn (string $word) => $word !== '')
            ->map(fn (string $word) => $word.'*')
            ->implode(' ');
    }

    /**
     * The indexed document types a clinic user may find in their own clinic.
     *
     * @return list<class-string>
     */
    protected function clinicTypesFor(User $user): array
    {
        return array_keys(array_filter(
            self::CLINIC_DOCUMENT_TYPES,
            fn (ClinicPermission $permission) => $user->hasClinicPermission($permission),
        ));
    }

    /**
     * Whether this user is a clinic tenant rather than HQ.
     *
     * Master admins are excluded here as well as by their own case, because the
     * global bypass is the source of truth for what they may reach.
     */
    protected function isTenantUser(User $user): bool
    {
        return $user->user_type === UserType::ClinicUser;
    }
}
