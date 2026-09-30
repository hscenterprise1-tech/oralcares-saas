<?php

namespace Database\Seeders;

use App\ActivityType;
use App\LeadPriority;
use App\LeadStatus;
use App\Models\Activity;
use App\Models\Clinic;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Task;
use App\QuotationStatus;
use Illuminate\Database\Seeder;

class ExecutiveDashboardSeeder extends Seeder
{
    public function run(): void
    {
        Clinic::firstOrCreate(['slug' => 'apex-dental-1'], ['name' => 'Apex Dental Care Main Branch', 'status' => 'active']);
        Clinic::firstOrCreate(['slug' => 'citycare-clinic'], ['name' => 'CityCare Multi-Specialty Clinic', 'status' => 'active']);
        Clinic::firstOrCreate(['slug' => 'smile-align-center'], ['name' => 'SmileAlign IVF & Dental Center', 'status' => 'active']);

        $lead1 = Lead::firstOrCreate(
            ['email' => 'dr.vikram@apexdental.com'],
            [
                'company_name' => 'Apex Dental Care',
                'contact_name' => 'Dr. Vikram Seth',
                'status' => LeadStatus::DemoScheduled,
                'priority' => LeadPriority::High,
                'source' => 'website',
                'expected_mrr' => 18400,
            ]
        );

        $lead2 = Lead::firstOrCreate(
            ['email' => 'contact@maxcarehospital.com'],
            [
                'company_name' => 'MaxCare Super Specialty',
                'contact_name' => 'Rajesh Gupta',
                'status' => LeadStatus::QuotationSent,
                'priority' => LeadPriority::High,
                'source' => 'referral',
                'expected_mrr' => 45000,
            ]
        );

        if ($lead1) {
            CompanyQuotation::firstOrCreate(
                ['lead_id' => $lead1->id],
                ['quotation_number' => 'QUO-APEX-001', 'total_amount' => 45000, 'status' => QuotationStatus::Draft, 'notes' => 'Apex Dental Care Onboarding']
            );

            Activity::firstOrCreate(
                ['description' => 'Apex Dental Care Onboarding Quotation Issued'],
                [
                    'subject_type' => Lead::class,
                    'subject_id' => $lead1->id,
                    'type' => ActivityType::Quotation,
                    'properties' => ['value' => '45000'],
                ]
            );
        }

        if ($lead2) {
            CompanyQuotation::firstOrCreate(
                ['lead_id' => $lead2->id],
                ['quotation_number' => 'QUO-MAX-001', 'total_amount' => 120000, 'status' => QuotationStatus::Draft, 'notes' => '25% Custom Enterprise Discount']
            );

            Activity::firstOrCreate(
                ['description' => 'SmileAlign Multi-Specialty Clinic Renewed'],
                [
                    'subject_type' => Lead::class,
                    'subject_id' => $lead2->id,
                    'type' => ActivityType::Agreement,
                    'properties' => ['value' => '28000'],
                ]
            );
        }

        Task::firstOrCreate(
            ['title' => 'Approve AWS Scaling Purchase Order'],
            ['status' => 'pending', 'priority' => 'high', 'due_at' => now()->addDays(2)]
        );

        Task::firstOrCreate(
            ['title' => 'Review Q3 Hospital Expansion Terms'],
            ['status' => 'pending', 'priority' => 'medium', 'due_at' => now()->addDays(3)]
        );
    }
}
