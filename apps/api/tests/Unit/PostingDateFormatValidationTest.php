<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Contract for posting_date on POST bodies that create PostingGroups: required, Y-m-d.
 */
class PostingDateFormatValidationTest extends TestCase
{
    private function postingDateRules(): array
    {
        return [
            'posting_date' => ['required', 'date', 'date_format:Y-m-d'],
        ];
    }

    public function test_valid_y_m_d_passes(): void
    {
        $v = Validator::make(['posting_date' => '2024-06-15'], $this->postingDateRules());
        $this->assertFalse($v->fails());
    }

    public function test_iso_datetime_rejected(): void
    {
        $v = Validator::make(['posting_date' => '2024-06-15T12:00:00Z'], $this->postingDateRules());
        $this->assertTrue($v->fails());
    }

    public function test_missing_posting_date_rejected(): void
    {
        $v = Validator::make([], $this->postingDateRules());
        $this->assertTrue($v->fails());
    }

    public function test_non_date_string_rejected(): void
    {
        $v = Validator::make(['posting_date' => 'not-a-date'], $this->postingDateRules());
        $this->assertTrue($v->fails());
    }
}
