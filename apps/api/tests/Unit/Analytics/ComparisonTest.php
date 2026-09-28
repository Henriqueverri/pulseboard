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

    public function test_percentages_are_one_decimal_floats_with_relative_change(): void
    {
        $this->assertSame(['value' => 65.3, 'previous' => 61.8, 'change' => 5.7], Comparison::ofPercentages(653, 618)->jsonSerialize());
        $this->assertSame(['value' => 50.0, 'previous' => 100.0, 'change' => -50.0], Comparison::ofPercentages(500, 1000)->jsonSerialize());
        $this->assertSame(0.0, Comparison::ofPercentages(250, 250)->change);
        $this->assertSame('{"value":50.0,"previous":0.0,"change":null}', json_encode(Comparison::ofPercentages(500, 0), JSON_PRESERVE_ZERO_FRACTION));
    }

    public function test_zero_and_undefined_percentages(): void
    {
        $this->assertSame(['value' => 0.0, 'previous' => 0.0, 'change' => 0.0], Comparison::ofPercentages(0, 0)->jsonSerialize());
        $this->assertSame(-100.0, Comparison::ofPercentages(0, 300)->change);
        $this->assertSame(['value' => null, 'previous' => 40.0, 'change' => null], Comparison::ofPercentages(null, 400)->jsonSerialize());
        $this->assertSame(['value' => null, 'previous' => null, 'change' => null], Comparison::ofPercentages(null, null)->jsonSerialize());
    }
}
