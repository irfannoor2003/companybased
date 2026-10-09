<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The office attendance QR code the Super Admin configures under
 * Settings → Company. The text field had no way to see or download the code it
 * produces, so the admin had to guess whether the printed code matched.
 */
class OfficeQrCodeSettingsTest extends TestCase
{
    use SeedsDatabase;

    private function superAdmin(): User
    {
        return $this->userForRole('Super Admin');
    }

    public function test_the_qr_code_endpoint_returns_a_png(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('settings.company.attendance-qr-code', ['text' => 'HQ-SCAN-2026']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');

        // PNG magic bytes.
        $this->assertSame(
            "\x89PNG\r\n\x1a\n",
            substr($response->getContent(), 0, 8),
            'the endpoint must return a real PNG',
        );
    }

    public function test_the_qr_code_previews_unsaved_text(): void
    {
        Setting::set('company.qr_code_text', 'SAVED-CODE');
        Setting::flushCache();

        $response = $this->actingAs($this->superAdmin())
            ->get(route('settings.company.attendance-qr-code', ['text' => 'UNSAVED-CODE']));

        $response->assertOk();
        $this->assertNotSame('', $response->getContent());
    }

    public function test_a_different_text_produces_a_different_qr_code(): void
    {
        $superAdmin = $this->superAdmin();

        $first = $this->actingAs($superAdmin)
            ->get(route('settings.company.attendance-qr-code', ['text' => 'CODE-ONE']))
            ->getContent();

        $second = $this->actingAs($superAdmin)
            ->get(route('settings.company.attendance-qr-code', ['text' => 'CODE-TWO']))
            ->getContent();

        $this->assertNotSame($first, $second, 'the preview must track the typed text');
    }

    public function test_a_blank_text_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('settings.company.attendance-qr-code', ['text' => '   ']))
            ->assertStatus(422);
    }

    public function test_the_settings_page_offers_a_qr_download(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(route('settings.company'));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('Download PNG', $html, 'the QR download option must be on the page');
        $this->assertStringContainsString('office-attendance-qr-code.png', $html);
        $this->assertStringContainsString(
            'attendance-qr-code',
            $html,
            'the page must point the preview and download at the QR endpoint',
        );
        $this->assertStringContainsString('officeQrPreview', $html);
    }

    public function test_a_user_without_permission_cannot_render_the_qr_code(): void
    {
        $this->actingAs($this->userForRole('Salesman'))
            ->get(route('settings.company.attendance-qr-code', ['text' => 'HQ-SCAN-2026']))
            ->assertForbidden();
    }
}
