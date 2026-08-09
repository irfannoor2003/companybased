<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\Carbon;

/**
 * Attendance engine. All behaviour is driven by per-company rules stored in
 * settings (`attendance.*`) so policies never require code changes.
 *
 * Rules (defaults in rulesDefaults()):
 *   shift_start / shift_end          Standard working hours ("09:00"/"17:30")
 *   grace_minutes                    Check-in within this window = Present
 *   short_leave_threshold_minutes    Checking out earlier than shift_end minus this = Short Leave
 *   half_day_cutoff_minutes          Check-in at/after shift_start + this = Half Day
 *   weekend_days                     Array of "Sat"/"Sun" style day names (non-working)
 *   deduction_*_type / *_amount      Flat amount or % of daily rate per Late/Short Leave/Absent
 */
class AttendanceService
{
    public function rules(): array
    {
        return [
            'shift_start' => settings('attendance.shift_start', '09:00'),
            'shift_end' => settings('attendance.shift_end', '17:30'),
            'grace_minutes' => (int) settings('attendance.grace_minutes', 5),
            'short_leave_threshold_minutes' => (int) settings('attendance.short_leave_threshold_minutes', 60),
            'half_day_cutoff_minutes' => (int) settings('attendance.half_day_cutoff_minutes', 240),
            'weekend_days' => $this->decodeWeekendDays(settings('attendance.weekend_days')),
            'deduction_late_type' => settings('attendance.deduction_late_type', 'flat'),
            'deduction_late_amount' => (float) settings('attendance.deduction_late_amount', 0),
            'deduction_short_leave_type' => settings('attendance.deduction_short_leave_type', 'flat'),
            'deduction_short_leave_amount' => (float) settings('attendance.deduction_short_leave_amount', 0),
            'deduction_absent_type' => settings('attendance.deduction_absent_type', 'percent'),
            'deduction_absent_amount' => (float) settings('attendance.deduction_absent_amount', 100),
        ];
    }

    private function decodeWeekendDays(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value) {
            $decoded = json_decode((string) $value, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['Sat', 'Sun'];
    }

    public function isWeekend(Carbon|string $date): bool
    {
        $day = $date instanceof Carbon ? $date->format('D') : Carbon::parse($date)->format('D');

        return in_array($day, $this->rules()['weekend_days'], true);
    }

    /**
     * Record a clock-in/clock-out scan (QR, fingerprint device or manual).
     * Scans toggle: no record today => clock-in, existing record => clock-out.
     */
    public function recordScan(Employee $employee, string $method = 'manual', ?Carbon $at = null, ?string $notes = null): array
    {
        if (! $employee->attendance_enabled) {
            return ['status' => 'error', 'message' => 'Attendance is disabled for this employee.'];
        }

        $at = $at ?? now();

        $record = AttendanceRecord::withTrashed()
            ->firstOrNew([
                'employee_id' => $employee->id,
                'attendance_date' => $at->toDateString(),
            ]);

        if ($record->exists) {
            if ($record->check_out_at) {
                return ['status' => 'error', 'message' => 'Already checked out for '.$at->toDateString().'.'];
            }

            $record->check_out_at = $at;
            $record->method = $method;
            $record->notes = $notes;
            $this->applyRules($record, $at);
            $record->save();

            return ['status' => 'success', 'type' => 'out', 'record' => $record];
        }

        $record->fill([
            'employee_id' => $employee->id,
            'attendance_date' => $at->toDateString(),
            'check_in_at' => $at,
            'method' => $method,
            'notes' => $notes,
            'is_weekend' => $this->isWeekend($at),
        ]);
        $this->applyRules($record, $at);
        $record->save();

        return ['status' => 'success', 'type' => 'in', 'record' => $record];
    }

    /**
     * Apply shift/grace/status rules to a record (recomputes status + work time).
     */
    public function applyRules(AttendanceRecord $record, ?Carbon $at = null): void
    {
        $rules = $this->rules();
        $date = Carbon::parse($record->attendance_date->toDateString());

        $shiftStart = Carbon::parse($date->toDateString().' '.$rules['shift_start']);
        $shiftEnd = Carbon::parse($date->toDateString().' '.$rules['shift_end']);

        $record->shift_start = $rules['shift_start'];
        $record->shift_end = $rules['shift_end'];
        $record->grace_minutes = $rules['grace_minutes'];

        if (! $record->check_in_at) {
            return;
        }

        $checkIn = Carbon::parse($record->check_in_at);

        if ($checkIn->lte($shiftStart->copy()->addMinutes($rules['grace_minutes']))) {
            $record->status = 'present';
        } elseif ($checkIn->lt($shiftStart->copy()->addMinutes($rules['half_day_cutoff_minutes']))) {
            $record->status = 'late';
        } else {
            $record->status = 'half_day';
        }

        if ($record->check_out_at) {
            $checkOut = Carbon::parse($record->check_out_at);
            $workMinutes = max(0, (int) $checkIn->diffInMinutes($checkOut));
            $record->work_minutes = $workMinutes;

            $available = (int) $shiftStart->diffInMinutes($shiftEnd);
            $earlyCutoff = max(0, $available - $rules['short_leave_threshold_minutes']);

            if ($workMinutes > 0 && $workMinutes < $earlyCutoff && $record->status !== 'half_day') {
                $record->status = 'short_leave';
            }
        }
    }

    /**
     * Flat or percentage-of-daily-rate deduction for a given attendance breach.
     */
    public function deductionFor(string $ruleTypeKey, string $ruleAmountKey, float $dailyRate, array $rules): float
    {
        $type = $rules[$ruleTypeKey] ?? 'flat';
        $amount = (float) ($rules[$ruleAmountKey] ?? 0);

        if ($type === 'percent') {
            return round($dailyRate * $amount / 100, 2);
        }

        return round($amount, 2);
    }

    public function dailyRate(float $gross, int $workingDays): float
    {
        return $workingDays > 0 ? round($gross / $workingDays, 2) : 0.0;
    }

    /**
     * Defaults used by the rules settings page and to seed sane values.
     */
    public static function defaults(): array
    {
        return [
            'shift_start' => '09:00',
            'shift_end' => '17:30',
            'grace_minutes' => '5',
            'short_leave_threshold_minutes' => '60',
            'half_day_cutoff_minutes' => '240',
            'weekend_days' => ['Sat', 'Sun'],
            'deduction_late_type' => 'flat',
            'deduction_late_amount' => '0',
            'deduction_short_leave_type' => 'flat',
            'deduction_short_leave_amount' => '0',
            'deduction_absent_type' => 'percent',
            'deduction_absent_amount' => '100',
        ];
    }
}
