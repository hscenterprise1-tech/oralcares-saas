<?php

namespace App\Shared\Search\Repositories;

use App\Models\Clinic;
use App\Models\User;
use App\Shared\Search\Models\SearchDocument;
use App\UserType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SearchRepository
{
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
         * This read `$user->clinic_id`, and `users` has no such column — so the
         * condition was never true and nothing was ever scoped. Every
         * authenticated user, clinic tenants included, could read the whole
         * index through the command palette: HQ's leads, accounts, commercial
         * documents and employee records.
         *
         * The boundary is the one the portals already enforce. HQ oversees every
         * tenant, so a company user and a master admin see the index whole; a
         * clinic tenant sees only documents belonging to its own organisation's
         * clinics, and never a document that belongs to HQ rather than a clinic.
         */
        if ($this->isTenantUser($user)) {
            /*
             * No organisation, no documents. Matching on a null
             * `organization_id` would instead hand this user every clinic row
             * that also has none — an unrelated set that happens to share a
             * missing value.
             */
            $clinicIds = $user->organization_id
                ? Clinic::query()->where('organization_id', $user->organization_id)->pluck('id')
                : collect();

            $q->whereIn('clinic_id', $clinicIds);
        }

        // 2. Search Query Matching (MySQL FULLTEXT or SQLite LIKE fallback)
        if (! empty($query)) {
            if (DB::getDriverName() === 'mysql') {
                $q->whereRaw('MATCH(title, subtitle, searchable_text) AGAINST(? IN BOOLEAN MODE)', [$query.'*']);
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
        if (! empty($query) && DB::getDriverName() === 'mysql') {
            $q->orderByRaw('MATCH(title, subtitle, searchable_text) AGAINST(? IN BOOLEAN MODE) DESC', [$query.'*']);
        } else {
            $q->latest('updated_at');
        }

        return $q->paginate($perPage);
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
