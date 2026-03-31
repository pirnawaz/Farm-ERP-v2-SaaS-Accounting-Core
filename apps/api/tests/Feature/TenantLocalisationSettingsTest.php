<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\TenantContext;
use App\Support\TenantLocalisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantLocalisationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
    }

    public function test_allow_lists_include_required_entries(): void
    {
        $this->assertContains('PKR', TenantLocalisation::CURRENCY_CODES);
        $this->assertContains('sd-PK', TenantLocalisation::LOCALE_CODES);
        foreach (['Asia/Karachi', 'Asia/Dubai', 'Australia/Sydney', 'UTC'] as $tz) {
            $this->assertContains($tz, TenantLocalisation::TIMEZONE_IDS);
        }
    }

    public function test_tenant_admin_can_update_localisation_with_allowed_values(): void
    {
        $tenant = Tenant::create([
            'name' => 'Loc Test',
            'status' => Tenant::STATUS_ACTIVE,
            'currency_code' => 'GBP',
            'locale' => 'en-GB',
            'timezone' => 'Europe/London',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/settings/tenant', [
                'currency_code' => 'USD',
                'locale' => 'sd-PK',
                'timezone' => 'Asia/Singapore',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'currency_code' => 'USD',
            'locale' => 'sd-PK',
            'timezone' => 'Asia/Singapore',
        ]);

        $tenant->refresh();
        $this->assertSame('USD', $tenant->currency_code);
        $this->assertSame('sd-PK', $tenant->locale);
        $this->assertSame('Asia/Singapore', $tenant->timezone);
    }

    public function test_invalid_currency_is_rejected(): void
    {
        $tenant = Tenant::create([
            'name' => 'Loc Test',
            'status' => Tenant::STATUS_ACTIVE,
            'currency_code' => 'PKR',
            'locale' => 'en-PK',
            'timezone' => 'Asia/Karachi',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/settings/tenant', [
                'currency_code' => 'QQQ',
                'locale' => 'en-PK',
                'timezone' => 'Asia/Karachi',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['currency_code']);
    }

    public function test_invalid_timezone_is_rejected_when_switching_from_allowed_value(): void
    {
        $tenant = Tenant::create([
            'name' => 'Loc Test',
            'status' => Tenant::STATUS_ACTIVE,
            'currency_code' => 'PKR',
            'locale' => 'en-PK',
            'timezone' => 'Asia/Karachi',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/settings/tenant', [
                'currency_code' => 'PKR',
                'locale' => 'en-PK',
                'timezone' => 'Moon/Craters',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['timezone']);
    }

    public function test_legacy_timezone_not_in_allow_list_can_be_submitted_unchanged(): void
    {
        $tenant = Tenant::create([
            'name' => 'Loc Test',
            'status' => Tenant::STATUS_ACTIVE,
            'currency_code' => 'PKR',
            'locale' => 'en-PK',
            'timezone' => 'Europe/Oslo',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/settings/tenant', [
                'currency_code' => 'PKR',
                'locale' => 'en-PK',
                'timezone' => 'Europe/Oslo',
            ]);

        $response->assertStatus(200);
        $this->assertSame('Europe/Oslo', $response->json('timezone'));
    }

    public function test_legacy_currency_not_in_allow_list_can_be_submitted_unchanged(): void
    {
        $tenant = Tenant::create([
            'name' => 'Loc Test',
            'status' => Tenant::STATUS_ACTIVE,
            'currency_code' => 'ZZZ',
            'locale' => 'en-PK',
            'timezone' => 'Asia/Karachi',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/settings/tenant', [
                'currency_code' => 'ZZZ',
                'locale' => 'en-PK',
                'timezone' => 'Asia/Karachi',
            ]);

        $response->assertStatus(200);
        $this->assertSame('ZZZ', $response->json('currency_code'));
    }

    public function test_locale_is_normalized_on_save(): void
    {
        $tenant = Tenant::create([
            'name' => 'Loc Test',
            'status' => Tenant::STATUS_ACTIVE,
            'currency_code' => 'PKR',
            'locale' => 'en-PK',
            'timezone' => 'Asia/Karachi',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/settings/tenant', [
                'currency_code' => 'PKR',
                'locale' => 'en-gb',
                'timezone' => 'Asia/Karachi',
            ]);

        $response->assertStatus(200);
        $this->assertSame('en-GB', $response->json('locale'));

        $tenant->refresh();
        $this->assertSame('en-GB', $tenant->locale);
    }

    public function test_get_returns_persisted_localisation(): void
    {
        $tenant = Tenant::create([
            'name' => 'Loc Test',
            'status' => Tenant::STATUS_ACTIVE,
            'currency_code' => 'EUR',
            'locale' => 'de-DE',
            'timezone' => 'Europe/Berlin',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/settings/tenant');

        $response->assertStatus(200);
        $response->assertJson([
            'currency_code' => 'EUR',
            'locale' => 'de-DE',
            'timezone' => 'Europe/Berlin',
        ]);
    }
}
