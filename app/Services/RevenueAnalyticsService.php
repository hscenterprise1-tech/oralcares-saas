<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Models\PatientBillingRefund;

class RevenueAnalyticsService
{
    /**
     * Compute revenue figures, outstanding receivables, collections, and refunds.
     */
    public function getRevenueMetrics(Clinic $clinic, ?Branch $branch, string $startDate, string $endDate): array
    {
        $billingQuery = PatientBilling::whereHas('patient', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

        $paymentQuery = PatientBillingPayment::whereHas('billing.patient', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

        $refundQuery = PatientBillingRefund::whereHas('billing.patient', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);

        if ($branch) {
            $billingQuery->where('branch_id', $branch->id);
            $paymentQuery->whereHas('billing', function ($q) use ($branch) {
                $q->where('branch_id', $branch->id);
            });
            $refundQuery->whereHas('billing', function ($q) use ($branch) {
                $q->where('branch_id', $branch->id);
            });
        }

        $totalInvoiced = (float) $billingQuery->sum('total_amount');
        $outstanding = (float) $billingQuery->sum('outstanding_balance');
        $totalCollected = (float) $paymentQuery->sum('amount');
        $totalRefunded = (float) $refundQuery->sum('amount');

        $methods = [];
        $payments = $paymentQuery->get();
        foreach ($payments as $p) {
            $methodName = $p->payment_method->value ?? 'unknown';
            $methods[$methodName] = ($methods[$methodName] ?? 0.0) + (float) $p->amount;
        }

        return [
            'total_invoiced' => $totalInvoiced,
            'total_collected' => $totalCollected,
            'outstanding_receivables' => $outstanding,
            'total_refunded' => $totalRefunded,
            'payment_method_distribution' => $methods,
        ];
    }
}
