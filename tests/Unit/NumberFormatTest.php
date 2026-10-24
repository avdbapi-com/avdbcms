<?php

namespace Tests\Unit;

use App\Support\NumberFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AVDBCMS 1.2.1: NumberFormat::fileSize() keeps the zeros of a whole number. Before 1.2.1 formatDecimal() stripped
 * trailing zeros even without decimals, so 100 B read "1 B", 120 KB "12 KB", 500 MB "5 MB" (the free plan's max
 * file size on the Premium page and in the upload "file too large" message) and 1000 B "1, B".
 *
 * Proposed for tests/Unit/ together with proposed/NumberFormat.patch (lane A does not own app/Support or tests/).
 */
class NumberFormatTest extends TestCase
{
    #[DataProvider('fileSizes')]
    public function test_file_sizes_keep_whole_numbers_and_trim_only_decimals(mixed $bytes, string $text): void
    {
        $this->assertSame($text, NumberFormat::fileSize($bytes));
    }

    public static function fileSizes(): array
    {
        return [
            'nothing' => [0, '0 B'],
            'negative' => [-5, '0 B'],
            '100 B' => [100, '100 B'],
            '300 B' => [300, '300 B'],
            '1000 B' => [1000, '1,000 B'],
            '1023 B' => [1023, '1,023 B'],
            '1 KB' => [1024, '1 KB'],
            '1.5 KB' => [1536, '1.5 KB'],
            '35.3 KB' => [36149, '35.3 KB'],
            '100 KB' => [102400, '100 KB'],
            '120 KB' => [122880, '120 KB'],
            '512 KB' => [524288, '512 KB'],
            '1 MB' => [1048576, '1 MB'],
            '2.5 MB' => [2621440, '2.5 MB'],
            '3.38 MB' => [3544186, '3.38 MB'],
            '10 MB' => [10485760, '10 MB'],
            '100 MB' => [104857600, '100 MB'],
            '500 MB (free plan max file size)' => [524288000, '500 MB'],
            '1.5 GB' => [1610612736, '1.5 GB'],
            '2 GB (premium max file size)' => [2147483648, '2 GB'],
            '5 GB (free plan storage)' => [5368709120, '5 GB'],
            '10 GB' => [10737418240, '10 GB'],
            '150 GB' => [161061273600, '150 GB'],
            'numeric string' => ['524288000', '500 MB'],
        ];
    }

    public function test_an_empty_size_prints_nothing(): void
    {
        $this->assertSame('', NumberFormat::fileSize(null));
        $this->assertSame('', NumberFormat::fileSize(''));
    }

    #[DataProvider('abbreviations')]
    public function test_abbreviations_share_the_same_decimal_rule(float|int $number, int $precision, string $text): void
    {
        $this->assertSame($text, NumberFormat::abbreviate($number, $precision));
    }

    public static function abbreviations(): array
    {
        return [
            'under a thousand' => [999, 1, '999'],
            'one thousand' => [1000, 1, '1K'],
            'one and a half thousand' => [1500, 1, '1.5K'],
            'twenty thousand' => [20000, 1, '20K'],
            'ten million' => [10000000, 1, '10M'],
            'whole number, no decimals' => [100000, 0, '100K'],
            'two billion' => [2000000000, 0, '2B'],
        ];
    }
}
