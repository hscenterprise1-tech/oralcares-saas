<?php

namespace App\Services\Marketing\Analytics;

use App\Models\MarketingContact;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MarketingAnalyticsFilter
{
    /**
     * Short codes the Analytics UI's period dropdown sends, mapped to the
     * canonical period this class actually understands. One contract, not
     * two — the UI keeps its simple labels, this map is the only place that
     * translates them.
     *
     * @var array<string, string>
     */
    protected const PERIOD_ALIASES = [
        '7d' => 'last_7_days',
        '30d' => 'last_30_days',
        '90d' => 'last_90_days',
        'all' => 'all_time',
    ];

    public string $period;

    public ?Carbon $startDate;

    public ?Carbon $endDate;

    public ?Carbon $prevStartDate;

    public ?Carbon $prevEndDate;

    public ?int $campaignId;

    public ?int $audienceId;

    public ?string $city;

    public ?string $tag;

    public ?int $whatsappAccountId;

    public ?string $leadStatus;

    public function __construct(array $params = [], ?Organization $organization = null)
    {
        $requestedPeriod = $params['period'] ?? 'last_30_days';
        $this->period = self::PERIOD_ALIASES[$requestedPeriod] ?? $requestedPeriod;
        $this->campaignId = ! empty($params['campaign_id']) ? (int) $params['campaign_id'] : null;
        $this->audienceId = ! empty($params['audience_id']) ? (int) $params['audience_id'] : null;
        $this->city = ! empty($params['city']) ? trim($params['city']) : null;
        $this->tag = ! empty($params['tag']) ? trim($params['tag']) : null;
        $this->whatsappAccountId = ! empty($params['whatsapp_account_id']) ? (int) $params['whatsapp_account_id'] : null;
        $this->leadStatus = ! empty($params['lead_status']) ? trim($params['lead_status']) : null;

        $timezone = MarketingContact::resolveBusinessTimezone($organization);
        $this->calculateDateBoundaries($params['start_date'] ?? null, $params['end_date'] ?? null, $timezone);
    }

    public static function fromRequest(Request $request, ?Organization $organization = null): self
    {
        return new self($request->all(), $organization);
    }

    /**
     * Every boundary is read in the organization's business timezone, then
     * converted to UTC before being stored — the same two-step Contacts and
     * Segments already use (`MarketingContact::resolveBusinessTimezone()` +
     * `->utc()`), so "today" means the business's calendar day, not the
     * server's, while still comparing cleanly against the UTC-stored
     * `created_at` columns every Analytics query filters on.
     */
    protected function calculateDateBoundaries(?string $customStart, ?string $customEnd, string $timezone): void
    {
        $now = Carbon::now($timezone);

        switch ($this->period) {
            case 'today':
                $this->startDate = $now->copy()->startOfDay()->utc();
                $this->endDate = $now->copy()->endOfDay()->utc();
                $this->prevStartDate = $now->copy()->subDay()->startOfDay()->utc();
                $this->prevEndDate = $now->copy()->subDay()->endOfDay()->utc();
                break;

            case 'yesterday':
                $this->startDate = $now->copy()->subDay()->startOfDay()->utc();
                $this->endDate = $now->copy()->subDay()->endOfDay()->utc();
                $this->prevStartDate = $now->copy()->subDays(2)->startOfDay()->utc();
                $this->prevEndDate = $now->copy()->subDays(2)->endOfDay()->utc();
                break;

            case 'last_7_days':
                $this->startDate = $now->copy()->subDays(6)->startOfDay()->utc();
                $this->endDate = $now->copy()->endOfDay()->utc();
                $this->prevStartDate = $now->copy()->subDays(13)->startOfDay()->utc();
                $this->prevEndDate = $now->copy()->subDays(7)->endOfDay()->utc();
                break;

            case 'last_90_days':
                $this->startDate = $now->copy()->subDays(89)->startOfDay()->utc();
                $this->endDate = $now->copy()->endOfDay()->utc();
                $this->prevStartDate = $now->copy()->subDays(179)->startOfDay()->utc();
                $this->prevEndDate = $now->copy()->subDays(90)->endOfDay()->utc();
                break;

            case 'this_month':
                $startOfMonth = $now->copy()->startOfMonth();
                $endOfToday = $now->copy()->endOfDay();
                $daysIn = $startOfMonth->diffInDays($endOfToday) + 1;
                $this->startDate = $startOfMonth->copy()->utc();
                $this->endDate = $endOfToday->copy()->utc();
                $prevStart = $now->copy()->subMonth()->startOfMonth();
                $this->prevStartDate = $prevStart->copy()->utc();
                $this->prevEndDate = $prevStart->copy()->addDays($daysIn - 1)->endOfDay()->utc();
                break;

            case 'last_month':
                $this->startDate = $now->copy()->subMonth()->startOfMonth()->utc();
                $this->endDate = $now->copy()->subMonth()->endOfMonth()->utc();
                $this->prevStartDate = $now->copy()->subMonths(2)->startOfMonth()->utc();
                $this->prevEndDate = $now->copy()->subMonths(2)->endOfMonth()->utc();
                break;

            case 'this_quarter':
                $this->startDate = $now->copy()->startOfQuarter()->utc();
                $this->endDate = $now->copy()->endOfDay()->utc();
                $this->prevStartDate = $now->copy()->subQuarter()->startOfQuarter()->utc();
                $this->prevEndDate = $now->copy()->subQuarter()->endOfQuarter()->utc();
                break;

            case 'this_year':
                $this->startDate = $now->copy()->startOfYear()->utc();
                $this->endDate = $now->copy()->endOfDay()->utc();
                $this->prevStartDate = $now->copy()->subYear()->startOfYear()->utc();
                $this->prevEndDate = $now->copy()->subYear()->endOfYear()->utc();
                break;

            case 'custom':
                $start = $customStart ? Carbon::parse($customStart, $timezone)->startOfDay() : $now->copy()->subDays(29)->startOfDay();
                $end = $customEnd ? Carbon::parse($customEnd, $timezone)->endOfDay() : $now->copy()->endOfDay();
                $diffDays = max(1, $start->diffInDays($end) + 1);
                $this->startDate = $start->copy()->utc();
                $this->endDate = $end->copy()->utc();
                $this->prevStartDate = $start->copy()->subDays($diffDays)->utc();
                $this->prevEndDate = $start->copy()->subDay()->endOfDay()->utc();
                break;

                // All time has no lower bound and no meaningful "previous
                // period" to compare against — every date-scoped query in the
                // Analytics services already skips its `whereBetween` clause
                // when `startDate` is null, so leaving it null is what makes
                // "all time" actually mean every record, not a stand-in range.
            case 'all_time':
                $this->startDate = null;
                $this->endDate = $now->copy()->endOfDay()->utc();
                $this->prevStartDate = null;
                $this->prevEndDate = null;
                break;

            case 'last_30_days':
            default:
                $this->period = 'last_30_days';
                $this->startDate = $now->copy()->subDays(29)->startOfDay()->utc();
                $this->endDate = $now->copy()->endOfDay()->utc();
                $this->prevStartDate = $now->copy()->subDays(59)->startOfDay()->utc();
                $this->prevEndDate = $now->copy()->subDays(30)->endOfDay()->utc();
                break;
        }
    }

    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'start_date' => $this->startDate?->toDateString(),
            'end_date' => $this->endDate?->toDateString(),
            'prev_start_date' => $this->prevStartDate?->toDateString(),
            'prev_end_date' => $this->prevEndDate?->toDateString(),
            'campaign_id' => $this->campaignId,
            'audience_id' => $this->audienceId,
            'city' => $this->city,
            'tag' => $this->tag,
            'whatsapp_account_id' => $this->whatsappAccountId,
            'lead_status' => $this->leadStatus,
        ];
    }
}
