<?php

declare(strict_types=1);

namespace App\Http\Controllers\Company\CustomerSuccess;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\CustomerSuccess\OverviewService;
use App\Support\CrmScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The state of the customer book.
 *
 * Customer Success opened on a list of accounts sorted by name. At a hundred
 * and twenty thousand of them that is not a starting point — nobody browses a
 * book that size, and no list answers "is this month in trouble".
 *
 * Built on the shape CRM and Marketing already use: the headline figures and
 * the reader's own queue render immediately because they are why the page was
 * opened, and the heavier aggregates arrive after the first paint.
 *
 * It repeats no list. Each line in "needs attention" is a count and a link into
 * the worklist that holds those rows — two surfaces rendering the same rows is
 * how their counts start disagreeing.
 */
class OverviewController extends Controller
{
    public function __construct(protected OverviewService $overview) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Organization::class);

        $user = $request->user();

        return Inertia::render('Company/CustomerSuccess/Overview/Index', [
            'scope' => [
                'level' => CrmScope::level($user),
                'is_manager' => CrmScope::isManager($user),
            ],

            /*
             * Immediate: how big the book is, what it is worth, and what needs
             * somebody today. Both are single aggregate queries and both are
             * the reason the page was opened.
             */
            'kpis' => $this->overview->kpis($user),
            'attention' => $this->overview->attention($user),

            /*
             * The named queue, in a group of its own so it arrives ahead of
             * everything else behind it. It is the reason somebody opens this
             * page — an aggregate says how many, only this says which — but it
             * sorts the projection rather than looking a row up, so it is not
             * something to hold the first paint for.
             */
            'workQueue' => Inertia::defer(fn () => $this->overview->workQueue($user), 'queue'),

            /*
             * Context behind the queue. Neither is what the reader is looking
             * at in the first second, and each is a grouped scan.
             */
            'distribution' => Inertia::defer(fn () => $this->overview->distribution($user), 'detail'),
            'renewalHorizon' => Inertia::defer(fn () => $this->overview->renewalHorizon($user), 'detail'),

            'can' => [
                'manageAccount' => Gate::allows('cs.accounts.manage'),
            ],
        ]);
    }
}
