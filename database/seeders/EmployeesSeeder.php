<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\Setting;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class EmployeesSeeder extends Seeder
{
    public function run(): void
    {
        $attendance = new AttendanceService;
        $rules = $attendance->rules();

        Setting::setMany($attendance->defaults(), 'attendance');

        mt_srand(20260215);

        $departments = [];
        foreach ([
            'Human Resources' => 'HR',
            'Engineering' => 'ENG',
            'Sales' => 'SAL',
            'Finance' => 'FIN',
            'Operations' => 'OPS',
        ] as $name => $code) {
            $departments[$name] = Department::firstOrCreate(
                ['name' => $name],
                [
                    'code' => $code,
                    'description' => "Department of {$name}.",
                    'is_active' => true,
                ]
            );
        }

        $userByEmail = User::query()->pluck('id', 'email');

        $employees = [];
        $staff = [
            ['Amina', 'Diallo', 'EMP-001', 'Human Resources', 'HR Manager', 'hr@companybased.test'],
            ['Kwame', 'Osei', 'EMP-002', 'Engineering', 'Senior Engineer', null],
            ['Lena', 'Meyer', 'EMP-003', 'Engineering', 'Software Engineer', null],
            ['Omar', 'Haddad', 'EMP-004', 'Sales', 'Sales Representative', 'salesman@companybased.test'],
            ['Priya', 'Sharma', 'EMP-005', 'Finance', 'Accountant', 'accountant@companybased.test'],
            ['Tom', 'Brooks', 'EMP-006', 'Operations', 'Operations Supervisor', null],
            ['Sofia', 'Reyes', 'EMP-007', 'Sales', 'Sales Representative', null],
            ['David', 'Kim', 'EMP-008', 'Finance', 'Financial Analyst', null],
            ['Alex', 'Taylor', 'EMP-009', 'Operations', 'General Staff', 'employee@companybased.test'],
            ['Jordan', 'Lee', 'EMP-010', 'Operations', 'Inventory Clerk', 'inventory-manager@companybased.test'],
        ];

        foreach ($staff as [$firstName, $lastName, $code, $dept, $title, $email]) {
            $employees[] = Employee::firstOrCreate(
                ['employee_code' => $code],
                [
                    'user_id' => $email ? ($userByEmail[$email] ?? null) : null,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email ?: strtolower($firstName).'.'.strtolower($lastName).'@companybased.test',
                    'phone' => '+1 555 '.str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT),
                    'date_of_birth' => Carbon::now()->subYears(mt_rand(24, 50))->subMonths(mt_rand(0, 11))->subDays(mt_rand(0, 27)),
                    'date_hired' => Carbon::now()->subMonths(mt_rand(6, 60)),
                    'department_id' => $departments[$dept]->id,
                    'job_title' => $title,
                    'employment_status' => 'active',
                    'address' => mt_rand(1, 99).' Main Street',
                    'attendance_enabled' => true,
                ]
            );
        }

        $departments['Human Resources']->update(['head_of_department_id' => $employees[0]->id]);
        $departments['Engineering']->update(['head_of_department_id' => $employees[1]->id]);
        $departments['Sales']->update(['head_of_department_id' => $employees[3]->id]);

        $salaryMatrix = [
            [2500, 500, 200, 100],
            [3200, 600, 250, 150],
            [2800, 550, 220, 120],
            [2100, 400, 200, 100],
            [2600, 500, 220, 130],
            [2700, 550, 230, 140],
            [2000, 380, 180, 90],
            [2900, 580, 240, 140],
            [1800, 350, 150, 80],
            [2200, 420, 180, 90],
        ];

        foreach ($employees as $i => $employee) {
            SalaryStructure::firstOrCreate(
                ['employee_id' => $employee->id, 'effective_from' => Carbon::now()->startOfYear()],
                [
                    'basic_salary' => (string) $salaryMatrix[$i][0],
                    'housing_allowance' => (string) $salaryMatrix[$i][1],
                    'transport_allowance' => (string) $salaryMatrix[$i][2],
                    'other_allowance' => (string) $salaryMatrix[$i][3],
                    'is_active' => true,
                ]
            );
        }

        $this->seedAttendance($employees, $rules, $attendance);

        $previous = Carbon::now()->subMonth();
        $run = PayrollRun::create([
            'number' => next_document_number('payroll_run', 'PR'),
            'period_start' => $previous->startOfMonth(),
            'period_end' => $previous->endOfMonth(),
            'status' => 'draft',
            'total_gross' => '0.00',
            'total_deductions' => '0.00',
            'total_net' => '0.00',
            'currency' => settings('company.currency', 'USD'),
            'prepared_by' => $userByEmail['hr@companybased.test'] ?? null,
            'notes' => 'Seeded payroll for the previous month.',
        ]);

        (new PayrollService($attendance))->generate($run);

        Storage::disk('public')->put('employee-documents/seeded-contract.txt', 'Seeded employment contract placeholder for '.$employees[0]->fullName().'.');

        EmployeeDocument::create([
            'employee_id' => $employees[0]->id,
            'title' => 'Employment contract',
            'type' => 'Contract',
            'file_path' => 'employee-documents/seeded-contract.txt',
            'original_name' => 'employment-contract.txt',
            'notes' => 'Seeded placeholder document.',
            'uploaded_by' => $userByEmail['hr@companybased.test'] ?? null,
        ]);
    }

    private function seedAttendance(array $employees, array $rules, AttendanceService $attendance): void
    {
        $shiftStart = Carbon::parse($rules['shift_start']);
        $shiftEnd = Carbon::parse($rules['shift_end']);
        $grace = (int) $rules['grace_minutes'];
        $halfDayCutoff = (int) $rules['half_day_cutoff_minutes'];
        $threshold = (int) $rules['short_leave_threshold_minutes'];

        $cursor = Carbon::now()->subWeeks(6)->startOfDay();

        while ($cursor->lte(Carbon::now()->startOfDay())) {
            if ($attendance->isWeekend($cursor)) {
                $cursor->addDay();
                continue;
            }

            foreach ($employees as $employee) {
                $roll = mt_rand(1, 100);

                if ($roll > 97) {
                    continue; // absent
                }

                $base = $cursor->copy();

                if ($roll <= 78) {
                    $checkIn = $base->copy()->setTimeFromTimeString($shiftStart->format('H:i'))->addMinutes(mt_rand(0, $grace));
                    $checkOut = $base->copy()->setTimeFromTimeString($shiftEnd->format('H:i'))->addMinutes(mt_rand(0, 15));
                } elseif ($roll <= 88) {
                    $checkIn = $base->copy()->setTimeFromTimeString($shiftStart->format('H:i'))->addMinutes(mt_rand($grace + 1, max($grace + 1, $halfDayCutoff - 1)));
                    $checkOut = $base->copy()->setTimeFromTimeString($shiftEnd->format('H:i'))->addMinutes(mt_rand(0, 15));
                } elseif ($roll <= 94) {
                    $checkIn = $base->copy()->setTimeFromTimeString($shiftStart->format('H:i'))->addMinutes(mt_rand(0, $grace));
                    $earlyEnd = $shiftEnd->copy()->subMinutes(max(1, $threshold + 1));
                    $checkOut = $base->copy()->setTimeFromTimeString($earlyEnd->format('H:i'))->subMinutes(mt_rand(0, 60));
                } else {
                    $checkIn = $base->copy()->setTimeFromTimeString($shiftStart->format('H:i'))->addMinutes(mt_rand($halfDayCutoff, $halfDayCutoff + 120));
                    $checkOut = $base->copy()->setTimeFromTimeString($shiftEnd->format('H:i'))->subMinutes(mt_rand(0, 30));
                }

                $record = new AttendanceRecord([
                    'employee_id' => $employee->id,
                    'attendance_date' => $cursor->toDateString(),
                    'check_in_at' => $checkIn,
                    'check_out_at' => $checkOut,
                    'method' => ['manual', 'qr', 'fingerprint'][mt_rand(0, 2)],
                    'is_weekend' => false,
                ]);

                $attendance->applyRules($record);
                $record->save();
            }

            $cursor->addDay();
        }
    }
}
