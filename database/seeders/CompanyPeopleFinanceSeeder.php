<?php

namespace Database\Seeders;

use App\Models\CompanyDepartment;
use App\Models\CompanyEmployee;
use App\Models\CompanyExpense;
use App\Models\CompanyPayroll;
use App\Models\CompanyTeam;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo data for a local HQ workspace: fictional leadership staff with login
 * accounts, expenses and payroll. Not production seed data, and not called by
 * DatabaseSeeder.
 *
 * It refuses to run outside a local or testing environment, and even there
 * only when SEED_DEMO_DATA is switched on. The demo accounts get a random
 * password that is never stored anywhere readable or logged: use "Forgot
 * password" to sign in as one. An account that already exists is left as it
 * is, password included.
 */
class CompanyPeopleFinanceSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ! config('security.demo_seeding')) {
            throw new RuntimeException('CompanyPeopleFinanceSeeder is local demo data. It runs only in a local or testing environment with SEED_DEMO_DATA=true.');
        }

        // 1. Fetch/Create Departments
        $salesDept = CompanyDepartment::firstOrCreate(['code' => 'SALES'], [
            'name' => 'Sales & Business Development',
            'description' => 'Enterprise sales, account executive team, and clinic partnerships.',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $engDept = CompanyDepartment::firstOrCreate(['code' => 'DEV'], [
            'name' => 'Engineering & Technology',
            'description' => 'Core platform, OVS design system, cloud infra, and radiology AI engine.',
            'status' => 'active',
            'sort_order' => 2,
        ]);

        $finDept = CompanyDepartment::firstOrCreate(['code' => 'FIN'], [
            'name' => 'Finance & Legal',
            'description' => 'Corporate finance, commercial billing, compliance, and payroll.',
            'status' => 'active',
            'sort_order' => 3,
        ]);

        $hrDept = CompanyDepartment::firstOrCreate(['code' => 'HR'], [
            'name' => 'People Operations',
            'description' => 'Talent acquisition, employee success, and organizational culture.',
            'status' => 'active',
            'sort_order' => 4,
        ]);

        // 2. Teams
        $salesTeam = CompanyTeam::firstOrCreate(['code' => 'ENT-SALES'], [
            'department_id' => $salesDept->id,
            'name' => 'Enterprise Sales Team',
            'status' => 'active',
        ]);

        $devTeam = CompanyTeam::firstOrCreate(['code' => 'CORE-PLAT'], [
            'department_id' => $engDept->id,
            'name' => 'Platform Engineering Team',
            'status' => 'active',
        ]);

        // 3. Create Seeded Employees & Linked Users
        $staffProfiles = [
            [
                'name' => 'Harsh Contractor',
                'email' => 'superadmin@oralcares.in',
                'code' => 'EMP-1001',
                'designation' => 'Chief Executive Officer',
                'department_id' => $salesDept->id,
                'team_id' => $salesTeam->id,
                'manager_id' => null,
                'status' => 'active',
                'joining_date' => '2024-01-15',
            ],
            [
                'name' => 'Rahul Gandhi',
                'email' => 'rahul.gandhi@oralcares.in',
                'code' => 'EMP-1002',
                'designation' => 'VP of Enterprise Sales',
                'department_id' => $salesDept->id,
                'team_id' => $salesTeam->id,
                'manager_id' => 1,
                'status' => 'active',
                'joining_date' => '2024-03-01',
            ],
            [
                'name' => 'Priya Sharma',
                'email' => 'priya.sharma@oralcares.in',
                'code' => 'EMP-1003',
                'designation' => 'Lead Frontend Architect',
                'department_id' => $engDept->id,
                'team_id' => $devTeam->id,
                'manager_id' => 1,
                'status' => 'active',
                'joining_date' => '2024-04-10',
            ],
            [
                'name' => 'Amitabh Mehta',
                'email' => 'amitabh.mehta@oralcares.in',
                'code' => 'EMP-1004',
                'designation' => 'Senior Financial Controller',
                'department_id' => $finDept->id,
                'team_id' => null,
                'manager_id' => 1,
                'status' => 'active',
                'joining_date' => '2024-06-01',
            ],
            [
                'name' => 'Neha Patel',
                'email' => 'neha.patel@oralcares.in',
                'code' => 'EMP-1005',
                'designation' => 'Head of People & HR',
                'department_id' => $hrDept->id,
                'team_id' => null,
                'manager_id' => 1,
                'status' => 'active',
                'joining_date' => '2024-07-15',
            ],
            [
                'name' => 'Karan Verma',
                'email' => 'karan.verma@oralcares.in',
                'code' => 'EMP-1006',
                'designation' => 'Customer Success Specialist',
                'department_id' => $salesDept->id,
                'team_id' => $salesTeam->id,
                'manager_id' => 2,
                'status' => 'invited',
                'joining_date' => '2026-05-01',
            ],
        ];

        $createdEmpIds = [];

        foreach ($staffProfiles as $prof) {
            $user = User::firstOrCreate(['email' => $prof['email']], [
                'name' => $prof['name'],
                'password' => Hash::make(Str::password(32)),
                'user_type' => 'company_user',
            ]);

            $emp = CompanyEmployee::firstOrCreate(['employee_code' => $prof['code']], [
                'user_id' => $user->id,
                'designation' => $prof['designation'],
                'department_id' => $prof['department_id'],
                'team_id' => $prof['team_id'],
                'manager_id' => $prof['manager_id'],
                'employment_status' => $prof['status'],
                'joining_date' => $prof['joining_date'],
                'timezone' => 'Asia/Kolkata',
                'notes' => 'Core HQ Leadership Member.',
            ]);

            $createdEmpIds[] = $emp->id;
        }

        // 4. Seed Company Expenses
        $expenses = [
            ['code' => 'EXP-8801', 'desc' => 'AWS Cloud Infrastructure — July 2026', 'cat' => 'infrastructure', 'amt' => 74500.00, 'date' => '2026-07-28', 'status' => 'approved'],
            ['code' => 'EXP-8802', 'desc' => 'OpenAI API Token Consumption', 'cat' => 'software', 'amt' => 38200.00, 'date' => '2026-08-02', 'status' => 'approved'],
            ['code' => 'EXP-8803', 'desc' => 'Healthcare Expo 2026 Sponsorship', 'cat' => 'marketing', 'amt' => 125000.00, 'date' => '2026-08-04', 'status' => 'approved'],
            ['code' => 'EXP-8804', 'desc' => 'HQ Office Lease & Maintenance', 'cat' => 'office', 'amt' => 95000.00, 'date' => '2026-08-05', 'status' => 'approved'],
            ['code' => 'EXP-8805', 'desc' => 'On-site Clinic Partner Travel Allowances', 'cat' => 'travel', 'amt' => 28400.00, 'date' => '2026-08-08', 'status' => 'pending'],
        ];

        $superAdminUser = User::where('email', 'superadmin@oralcares.in')->first() ?? User::first();

        foreach ($expenses as $exp) {
            CompanyExpense::firstOrCreate(['expense_number' => $exp['code']], [
                'description' => $exp['desc'],
                'category' => $exp['cat'],
                'amount' => $exp['amt'],
                'expense_date' => $exp['date'],
                'submitted_by' => $superAdminUser->id,
                'status' => $exp['status'],
            ]);
        }

        // 5. Seed Company Payroll Records
        $months = ['2026-06', '2026-07', '2026-08'];
        $salaryMap = [
            1 => 250000.00,
            2 => 180000.00,
            3 => 150000.00,
            4 => 135000.00,
            5 => 120000.00,
            6 => 75000.00,
        ];

        foreach ($months as $m) {
            foreach ($createdEmpIds as $empId) {
                $gross = $salaryMap[$empId] ?? 100000.00;
                $ded = round($gross * 0.12, 2);
                $net = $gross - $ded;

                CompanyPayroll::firstOrCreate([
                    'employee_id' => $empId,
                    'month' => $m,
                ], [
                    'payroll_code' => 'PAY-'.str_replace('-', '', $m).'-'.$empId,
                    'gross_amount' => $gross,
                    'deductions' => $ded,
                    'net_amount' => $net,
                    'status' => 'paid',
                    'paid_date' => $m.'-28',
                ]);
            }
        }
    }
}
