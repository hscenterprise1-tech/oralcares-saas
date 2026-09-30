<?php

namespace App\Shared\Search\Services;

use App\Models\User;
use App\Shared\Search\Models\SearchHistory;
use App\Shared\Search\Repositories\SearchRepository;
use Illuminate\Pagination\LengthAwarePaginator;

class SearchEngineService
{
    public function __construct(
        protected SearchRepository $repository
    ) {}

    /**
     * Perform a global search and log it into history.
     */
    public function search(User $user, string $query, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $results = $this->repository->search($user, $query, $filters, $perPage);

        // Log search history asynchronously or synchronously depending on scale
        // For now, doing it synchronously but only if there's a query string
        if (! empty($query) && $results->currentPage() === 1) {
            $this->logSearch($user, $query, $results->total());
        }

        return $results;
    }

    /**
     * Log search query to history.
     */
    protected function logSearch(User $user, string $query, int $count): void
    {
        // Prevent duplicate spamming by updating the most recent exact match if it's within the last hour
        $recent = SearchHistory::where('user_id', $user->id)
            ->where('query_string', $query)
            ->where('searched_at', '>=', now()->subHour())
            ->first();

        if ($recent) {
            $recent->update(['searched_at' => now(), 'results_count' => $count]);
        } else {
            SearchHistory::create([
                'user_id' => $user->id,
                'query_string' => $query,
                'results_count' => $count,
                'searched_at' => now(),
            ]);
        }
    }
}
