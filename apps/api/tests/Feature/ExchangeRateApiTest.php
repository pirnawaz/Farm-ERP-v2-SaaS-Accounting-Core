<?php

namespace Tests\Feature;

use App\Domains\Accounting\MultiCurrency\ExchangeRate;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_and_create_exchange_rate(): void
    {
        (new ModulesSeeder)->run();
        $tenant = \App\Models\Tenant::create(['name' => 'FX API', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2024-06-01',
            'base_currency_code' => 'GBP',
            'quote_currency_code' => 'EUR',
            'rate' => '1.15000000',
            'source' => 'seed',
        ]);

        $list = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/exchange-rates');
        $list->assertStatus(200);
        $this->assertCount(1, $list->json());

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/exchange-rates', [
                'rate_date' => '2024-06-15',
                'base_currency_code' => 'GBP',
                'quote_currency_code' => 'USD',
                'rate' => 1.27,
                'source' => 'manual',
            ]);
        $post->assertStatus(201);
        $this->assertSame('USD', $post->json('quote_currency_code'));
    }
}
