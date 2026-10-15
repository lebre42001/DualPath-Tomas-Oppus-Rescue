<?php

namespace Tests\Unit;

use App\Support\ReportedFatalityKeyword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportedFatalityKeywordTest extends TestCase
{
    #[DataProvider('descriptionsWithKeyword')]
    public function test_any_standalone_patay_word_triggers_the_conservative_flag(string $description): void
    {
        self::assertTrue(ReportedFatalityKeyword::matches($description));
    }

    public static function descriptionsWithKeyword(): array
    {
        return [
            'lowercase' => ['naa nay patay'],
            'phrase' => ['patay na ang driver'],
            'uppercase' => ['PATAY ang pasahero'],
            'negated mention follows the requested any-word rule' => ['walay patay apan adunay samaran'],
        ];
    }

    public function test_unrelated_text_does_not_trigger(): void
    {
        self::assertFalse(ReportedFatalityKeyword::matches('Naaksidente ang motor, naay samaran.'));
        self::assertFalse(ReportedFatalityKeyword::matches('Patayan ang dapit.'));
        self::assertFalse(ReportedFatalityKeyword::matches(null));
    }
}
