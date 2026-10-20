<?php
namespace Tests\Feature\Regression;

use App\Models\Setting;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

class QrCodeTextSaveRegressionTest extends TestCase
{
    use SeedsDatabase;

    public function test_entered_qr_code_text_persists(): void
    {
        $superAdmin = User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();

        $this->actingAs($superAdmin)
            ->put('/settings/company', ['qr_code_text' => 'REGRESSION-CODE-123'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Setting::flushCache();

        $this->assertSame('REGRESSION-CODE-123', (string) settings('company.qr_code_text'));
    }
}