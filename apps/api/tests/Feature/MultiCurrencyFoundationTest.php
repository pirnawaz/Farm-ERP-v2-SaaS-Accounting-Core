<?php

namespace Tests\Feature;

use App\Domains\Accounting\MultiCurrency\ExchangeRate;
use App\Domains\Accounting\MultiCurrency\FxRateResolver;
use App\Models\Account;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Tenant;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiCurrencyFoundationTest extends TestCase
{
    public function test_exchange_rates_table_stores_tenant_date_pair(): void
    {
        $tenant = Tenant::create(['name' => 'FX Tenant', 'status' => 'active', 'currency_code' => 'USD']);

        $row = ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2026-02-01',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => 1.1,
            'source' => 'manual',
        ]);

        $this->assertDatabaseHas('exchange_rates', [
            'id' => $row->id,
            'tenant_id' => $tenant->id,
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
        ]);
    }

    public function test_duplicate_exchange_rate_same_day_pair_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'FX Tenant 2', 'status' => 'active', 'currency_code' => 'USD']);

        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2026-02-01',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => 1.1,
        ]);

        $this->expectException(QueryException::class);

        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2026-02-01',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => 1.2,
        ]);
    }

    public function test_fx_rate_resolver_uses_latest_on_or_before_posting_date(): void
    {
        $tenant = Tenant::create(['name' => 'FX Tenant 3', 'status' => 'active', 'currency_code' => 'USD']);
        $resolver = new FxRateResolver;

        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2026-01-01',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => 1.0,
        ]);
        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2026-02-01',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => 1.2,
        ]);

        $this->assertSame('1', $resolver->rateForPostingDate($tenant->id, '2026-03-15', 'USD', 'USD'));
        $this->assertEqualsWithDelta(1.0, (float) $resolver->rateForPostingDate($tenant->id, '2026-01-15', 'USD', 'EUR'), 0.0000001);
        $this->assertEqualsWithDelta(1.2, (float) $resolver->rateForPostingDate($tenant->id, '2026-03-15', 'USD', 'EUR'), 0.0000001);
    }

    public function test_ledger_entry_defaults_base_amounts_for_single_currency(): void
    {
        $tenant = Tenant::create(['name' => 'LE Tenant', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);

        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $this->assertNotNull($bank);

        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'JOURNAL_ENTRY',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2026-01-10',
            'idempotency_key' => 'mc-test-'.Str::uuid(),
        ]);

        $pg->refresh();
        $this->assertSame('GBP', $pg->currency_code);
        $this->assertSame('GBP', $pg->base_currency_code);
        $this->assertEqualsWithDelta(1.0, (float) $pg->fx_rate, 0.0000001);

        $le = LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $bank->id,
            'debit_amount' => 50,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);

        $le->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $le->debit_amount_base, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $le->credit_amount_base, 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $le->fx_rate, 0.0000001);
        $this->assertSame('GBP', $le->base_currency_code);
    }
}
