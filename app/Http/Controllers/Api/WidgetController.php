<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Widgets\ExecutiveWidgetService;
use Illuminate\Http\JsonResponse;

class WidgetController extends Controller
{
    public function __construct(private ExecutiveWidgetService $service) {}

    public function getHeroData(): JsonResponse
    {
        return response()->json($this->service->getHeroData());
    }

    public function getKPIs(): JsonResponse
    {
        return response()->json($this->service->getKPIs());
    }

    public function getRevenueAnalytics(): JsonResponse
    {
        return response()->json($this->service->getRevenueAnalytics());
    }

    public function getSalesFunnel(): JsonResponse
    {
        return response()->json($this->service->getSalesFunnel());
    }

    public function getOperationsOverview(): JsonResponse
    {
        return response()->json($this->service->getOperationsOverview());
    }

    public function getActivityTimeline(): JsonResponse
    {
        return response()->json($this->service->getActivityTimeline());
    }

    public function getSystemHealth(): JsonResponse
    {
        return response()->json($this->service->getSystemHealth());
    }
}
