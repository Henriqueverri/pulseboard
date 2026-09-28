<?php

namespace Tests\Unit\Analytics;

use App\Support\Analytics\Comparison;
use PHPUnit\Framework\TestCase;

class ComparisonTest extends TestCase
{
    public function test_counts_report_the_percent_change_rounded_to_one_decimal(): void
    {
        $this->assertSame(['value' => 98, 'previous' => 81, 'change' => 21.0], Comparison::ofCounts(98, 81)->jsonSerialize());
        $this->assertSame(-33.3, Comparison::ofCounts(2, 3)->change);
        $this->assertSame(0.0, Comparison::ofCounts(5, 5)->change);
    }

    public function test_money_is_serialized_as_decimal_strings(): void
    {
        $comparison = Comparison::ofMoney(1250000, 1000000);

        $this->assertSame(['value' => '12500.00', 'previous' => '10000.00', 'change' => 25.0], $comparison->jsonSerialize());
        $this->assertSame(3.3, Comparison::ofMoney(12755, 12346)->change);
    }

    public function test_zero_previous_values(): void
    {
        $this->assertSame(0.0, Comparison::ofCounts(0, 0)->change);
        $this->assertNull(Comparison::ofCounts(3, 0)->change);
        $this->assertSame(-100.0, Comparison::ofCounts(0, 4)->change);
    }

    public function test_undefined_values_have_no_change(): void
    {
        $this->assertSame(['value' => null, 'previous' => '10.00', 'change' => null], Comparison::ofMoney(null, 1000)->jsonSerialize());
        $this->assertSame(['value' => '10.00', 'previous' => null, 'change' => null], Comparison::ofMoney(1000, null)->jsonSerialize());
    }
}
