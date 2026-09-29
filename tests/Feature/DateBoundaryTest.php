<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Guards the inclusive-date-range behaviour that payroll depends on.
 *
 * The important discovery behind this test: SQLite and MySQL store a cast
 * `date` column differently. SQLite round-trips the model's `date` cast as
 * '2026-09-11 00:00:00', which is string-greater than '2026-09-11', so a bare
 * `<=` comparison silently drops the final day of a period. MySQL stores a bare
 * '2026-09-11' and is unaffected.
 *
 * That means a payroll off-by-one can be a *test* artefact rather than a
 * production defect — worth knowing before "fixing" it as a live money bug. It
 * also means the suite has to be run on both drivers, because each hides the
 * other's mistakes.
 */
class DateBoundaryTest extends TestCase
{
    use SeedsDatabase;

    private function employee(): Employee
    {
        return Employee::create([
            'user_id' => null,
            'employee_code' => 'EMP-BOUND',
            'first_name' => 'Bound',
            'last_name' => 'Ary',
            'date_hired' => now()->subYear()->toDateString(),
            'employment_status' => 'active',
            'attendance_enabled' => true,
        ]);
    }

    public function test_the_final_day_of_a_period_is_included(): void
    {
        $employee = $this->employee();

        foreach (['2026-09-10', '2026-09-11'] as $date) {
            AttendanceRecord::create([
                'employee_id' => $employee->id,
                'attendance_date' => $date,
                'status' => 'present',
                'check_in_at' => $date.' 09:00:00',
                'method' => 'manual',
            ]);
        }

        $records = $employee->attendanceRecords()
            ->forPeriod('2026-09-07', '2026-09-11')
            ->get();

        $this->assertCount(2, $records, 'both days must fall inside the period');
        $this->assertSame(
            ['2026-09-10', '2026-09-11'],
            $records->map(fn ($r) => $r->attendance_date->toDateString())->sort()->values()->all(),
        );
    }

    public function test_a_period_excludes_days_outside_its_bounds(): void
    {
        $employee = $this->employee();

        foreach (['2026-09-05', '2026-09-10', '2026-09-15'] as $date) {
            AttendanceRecord::create([
                'employee_id' => $employee->id,
                'attendance_date' => $date,
                'status' => 'present',
                'check_in_at' => $date.' 09:00:00',
                'method' => 'manual',
            ]);
        }

        $records = $employee->attendanceRecords()
            ->forPeriod('2026-09-09', '2026-09-11')
            ->get();

        $this->assertCount(1, $records, 'only the day inside the window counts');
    }

    /**
     * Documents why this suite must run on both drivers: a bare string
     * comparison gives a different answer to whereDate() on SQLite than on
     * MySQL, so a green run on one database says nothing about the other.
     */
    public function test_the_two_drivers_store_cast_dates_differently(): void
    {
        $employee = $this->employee();

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-11',
            'status' => 'present',
            'check_in_at' => '2026-09-11 09:00:00',
            'method' => 'manual',
        ]);

        $raw = DB::table('attendance_records')->value('attendance_date');
        $driver = DB::connection()->getDriverName();

        $bare = AttendanceRecord::where('attendance_date', '<=', '2026-09-11')->count();
        $viaWhereDate = AttendanceRecord::whereDate('attendance_date', '<=', '2026-09-11')->count();

        $this->assertSame(1, $viaWhereDate, 'whereDate() is correct on every driver');

        if ($driver === 'sqlite') {
            $this->assertStringContainsString('00:00:00', (string) $raw, 'SQLite stores a full datetime');
            $this->assertSame(0, $bare, 'the bare comparison is wrong on SQLite — this is why scopeForPeriod uses whereDate()');
        } else {
            $this->assertSame('2026-09-11', $raw, 'MySQL stores a bare date');
            $this->assertSame(1, $bare, 'the bare comparison happens to work on MySQL');
        }
    }
}
