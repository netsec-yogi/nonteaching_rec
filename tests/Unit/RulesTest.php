<?php

namespace Tests\Unit;

use App\Rules\MaxWords;
use App\Services\ApplicationForm\Sections\EmploymentSection;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class RulesTest extends TestCase
{
    public function test_word_count(): void
    {
        $this->assertSame(0, MaxWords::count('   '));
        $this->assertSame(3, MaxWords::count("one  two\nthree"));
        $this->assertSame(2, MaxWords::count('गुणवत्ता प्रबंधन'));
    }

    public function test_employment_duration_in_months(): void
    {
        $this->assertSame(30, EmploymentSection::months(Carbon::parse('2015-01-01'), Carbon::parse('2017-06-30')));
        $this->assertSame(12, EmploymentSection::months(Carbon::parse('2020-01-01'), Carbon::parse('2020-12-31')));
        $this->assertSame(0, EmploymentSection::months(Carbon::parse('2020-01-01'), Carbon::parse('2020-01-10')));
    }
}
