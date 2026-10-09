<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The self-service clock-in flow.
 *
 * Two defects motivated these tests:
 *
 *  1. The button was wired as `@click="getLocation()"` on a submit button, so the
 *     form POSTed before the async geolocation callback had filled the hidden
 *     latitude/longitude inputs. The server's `required` rule then rejected the
 *     submission with "The latitude field is required."
 *  2. The radius check compared the browser coordinates against the office
 *     coordinates even when no office location was configured, so the distance
 *     was measured from null island and every clock-in was refused.
 */
class AttendanceLocationAndQrTest extends TestCase
{
    use SeedsDatabase;

    private const OFFICE_LAT = '31.5204';

    private const OFFICE_LNG = '74.3587';

    private const RADIUS = '500';

    private const QR_TEXT = 'HQ-SCAN-2026';

    /**
     * The office coordinates the Super Admin configured, plus the QR text that
     * is encoded into the printed code.
     */
    private function configureOfficeLocation(): void
    {
        Setting::setMany([
            'company.latitude' => self::OFFICE_LAT,
            'company.longitude' => self::OFFICE_LNG,
            'company.radius' => self::RADIUS,
            'company.qr_code_text' => self::QR_TEXT,
        ]);

        Setting::flushCache();
    }

    /**
     * Coordinates a few dozen metres from the office — inside the radius.
     */
    private function coordinatesNearOffice(): array
    {
        return ['latitude' => '31.5210', 'longitude' => '74.3590'];
    }

    private function employee(): User
    {
        return $this->employeeUserForRole('Employee');
    }

    public function test_clock_in_succeeds_within_the_office_radius(): void
    {
        $this->configureOfficeLocation();
        $employee = $this->employee();

        $response = $this->actingAs($employee)->post('/employees/my-attendance/mark', array_merge(
            $this->coordinatesNearOffice(),
            ['qr_text' => self::QR_TEXT],
        ));

        $response->assertSessionHasNoErrors();

        $record = AttendanceRecord::where('employee_id', Employee::where('user_id', $employee->id)->value('id'))
            ->whereDate('attendance_date', now())
            ->first();

        $this->assertNotNull($record, 'a clock-in record must be created');
        $this->assertNotNull($record->check_in_at);
        $this->assertNull($record->check_out_at, 'the first mark is a clock-in, not a clock-out');
    }

    public function test_clock_in_outside_the_office_radius_is_refused(): void
    {
        $this->configureOfficeLocation();
        $employee = $this->employee();

        // Roughly 300 km from Lahore.
        $this->actingAs($employee)->post('/employees/my-attendance/mark', [
            'latitude' => '34.0151',
            'longitude' => '71.5249',
            'qr_text' => self::QR_TEXT,
        ])->assertSessionHas('toasts');

        $this->assertSame(0, AttendanceRecord::count(), 'no record may be written from a mismatched location');
    }

    public function test_clock_in_requires_the_latitude_and_longitude_fields(): void
    {
        $this->configureOfficeLocation();
        $employee = $this->employee();

        // The exact request the geolocation race used to produce: the browser
        // submitted before the coordinates were read.
        $this->actingAs($employee)
            ->post('/employees/my-attendance/mark', ['qr_text' => self::QR_TEXT])
            ->assertSessionHasErrors(['latitude', 'longitude']);

        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_clock_in_is_refused_when_the_scanned_qr_text_does_not_match(): void
    {
        $this->configureOfficeLocation();
        $employee = $this->employee();

        $this->actingAs($employee)->post('/employees/my-attendance/mark', array_merge(
            $this->coordinatesNearOffice(),
            ['qr_text' => 'SOME-OTHER-CODE'],
        ))->assertSessionHas('toasts');

        $this->assertSame(0, AttendanceRecord::count());
    }

    /**
     * Regression: with no office location on file there is nothing to measure
     * against, so a clock-in inside the office must not be refused for being
     * "too far" from null island.
     */
    public function test_clock_in_is_allowed_when_no_office_location_is_configured(): void
    {
        Setting::setMany([
            'company.latitude' => null,
            'company.longitude' => null,
        ]);

        Setting::flushCache();

        $employee = $this->employee();

        $this->actingAs($employee)->post('/employees/my-attendance/mark', [
            'latitude' => '31.5204',
            'longitude' => '74.3587',
            'qr_text' => self::QR_TEXT,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, AttendanceRecord::count());
    }

    public function test_second_mark_of_the_day_clocks_the_employee_out(): void
    {
        $this->configureOfficeLocation();
        $employee = $this->employee();
        $payload = array_merge($this->coordinatesNearOffice(), ['qr_text' => self::QR_TEXT]);

        $this->actingAs($employee)->post('/employees/my-attendance/mark', $payload)->assertSessionHasNoErrors();
        $this->actingAs($employee)->post('/employees/my-attendance/mark', $payload)->assertSessionHasNoErrors();

        $record = AttendanceRecord::whereDate('attendance_date', now())->first();

        $this->assertNotNull($record->check_in_at);
        $this->assertNotNull($record->check_out_at, 'the second mark must clock out');
    }

    /**
     * The page decides the button label from today's record. An equality match
     * on the date string misses the row when the `date` cast round-trips as
     * 'Y-m-d 00:00:00', which leaves the button stuck on "Clock in" and hides
     * the clock-out time.
     */
    public function test_the_page_shows_todays_record_after_clocking_in(): void
    {
        $this->configureOfficeLocation();
        $employee = $this->employee();
        $payload = array_merge($this->coordinatesNearOffice(), ['qr_text' => self::QR_TEXT]);

        $this->actingAs($employee)->post('/employees/my-attendance/mark', $payload);

        $response = $this->actingAs($employee)->get('/employees/my-attendance');

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('Clock out', $html, 'the button must offer clock-out after a clock-in');
        $this->assertStringNotContainsString('Clock in', $html);
    }

    public function test_the_page_shows_the_clock_out_time_after_clocking_out(): void
    {
        $this->configureOfficeLocation();
        $employee = $this->employee();
        $payload = array_merge($this->coordinatesNearOffice(), ['qr_text' => self::QR_TEXT]);

        $this->actingAs($employee)->post('/employees/my-attendance/mark', $payload);
        $this->actingAs($employee)->post('/employees/my-attendance/mark', $payload);

        $html = $this->actingAs($employee)->get('/employees/my-attendance')->getContent();

        $this->assertStringContainsString(
            'Attendance completed for today',
            $html,
            'the completed state must be visible after clocking out',
        );
        $this->assertStringContainsString(now()->format('H:i'), $html, 'both times must be rendered');
    }

    public function test_my_attendance_page_renders_the_camera_scanner(): void
    {
        $employee = $this->employee();

        $response = $this->actingAs($employee)->get('/employees/my-attendance');

        $response->assertOk();

        $html = $response->getContent();

        // The live camera path, not a bare file input that falls back to the
        // gallery chooser.
        $this->assertStringContainsString('getCameras', $html, 'the live camera scanner must be used');
        $this->assertStringContainsString('qr-reader', $html);
        $this->assertStringContainsString('markAttendance', $html);

        // The form waits for geolocation before submitting.
        $this->assertStringContainsString('requestSubmit()', $html);
    }
}
