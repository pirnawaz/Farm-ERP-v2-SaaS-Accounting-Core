<?php

namespace Tests\Unit;

use App\Services\PostingIdempotencyService;
use PHPUnit\Framework\TestCase;

class PostingIdempotencyServiceTest extends TestCase
{
    public function test_effective_key_uses_client_value_when_non_empty(): void
    {
        $s = new PostingIdempotencyService();
        $this->assertSame('my-key', $s->effectiveKey('my-key', 'X', 'uuid'));
    }

    public function test_effective_key_trims_client_value(): void
    {
        $s = new PostingIdempotencyService();
        $this->assertSame('k', $s->effectiveKey('  k  ', 'X', 'uuid'));
    }

    public function test_effective_key_falls_back_to_source_when_null_or_blank(): void
    {
        $s = new PostingIdempotencyService();
        $this->assertSame('INVENTORY_TRANSFER:tr-1', $s->effectiveKey(null, 'INVENTORY_TRANSFER', 'tr-1'));
        $this->assertSame('INVENTORY_TRANSFER:tr-1', $s->effectiveKey('', 'INVENTORY_TRANSFER', 'tr-1'));
        $this->assertSame('INVENTORY_TRANSFER:tr-1', $s->effectiveKey('   ', 'INVENTORY_TRANSFER', 'tr-1'));
    }
}
