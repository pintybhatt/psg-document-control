<?php

namespace Tests\Feature;

use App\Authorization\NumberingFormat;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NumberingFormatTest extends TestCase
{
    /** @param array{pattern: string, company?: string, department?: string, document_type?: string}|null $format */
    #[DataProvider('formats')]
    public function test_rendering_uses_only_the_selected_components(?array $format, int $padding, int $sequence, string $expected): void
    {
        $this->assertSame($expected, NumberingFormat::render('EXAMPLE/OUT', $padding, 2026, $sequence, $format));
    }

    /** @return array<string, array{array<string, string>|null, int, int, string}> */
    public static function formats(): array
    {
        $codes = ['company' => 'EXAMPLE', 'department' => 'DEPT', 'document_type' => 'TYPE'];

        return [
            'legacy default' => [null, 4, 1, 'EXAMPLE/OUT/2026/0001'],
            'without year' => [['pattern' => '{company}-{department}-{document_type}-{sequence}'] + $codes, 4, 1, 'EXAMPLE-DEPT-TYPE-0001'],
            'literal suffix' => [['pattern' => '{company}-{department}-{document_type}-{sequence}-R00'] + $codes, 4, 1, 'EXAMPLE-DEPT-TYPE-0001-R00'],
            'two digit year' => [['pattern' => '{company}-{department}-{document_type}-{year2}-{sequence}'] + $codes, 4, 1, 'EXAMPLE-DEPT-TYPE-26-0001'],
            'four digit year and separators' => [['pattern' => '{company}/{department}_{document_type}.{year}.{sequence}'] + $codes, 3, 12, 'EXAMPLE/DEPT_TYPE.2026.012'],
            'prefix token' => [['pattern' => '{prefix}-{year2}-{sequence}'], 2, 1, 'EXAMPLE/OUT-26-01'],
            'minimum padding' => [['pattern' => 'TEST-{sequence}'], 1, 1, 'TEST-1'],
            'maximum padding' => [['pattern' => 'TEST-{sequence}'], 12, 1, 'TEST-000000000001'],
            'sequence is never truncated' => [['pattern' => 'TEST-{sequence}'], 2, 1234, 'TEST-1234'],
        ];
    }

    /** @param array<string, mixed> $format */
    #[DataProvider('invalidFormats')]
    public function test_invalid_definitions_fail_validation(array $format): void
    {
        $this->expectException(ValidationException::class);

        NumberingFormat::render('EXAMPLE', 4, 2026, 1, $format);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidFormats(): array
    {
        return [
            'empty definition' => [[]],
            'missing pattern' => [['company' => 'EXAMPLE']],
            'no sequence' => [['pattern' => '{company}-{year}', 'company' => 'EXAMPLE']],
            'repeated sequence' => [['pattern' => '{sequence}-{sequence}']],
            'unknown token' => [['pattern' => '{unknown}-{sequence}']],
            'malformed token' => [['pattern' => '{company-{sequence}']],
            'missing referenced code' => [['pattern' => '{department}-{sequence}']],
            'empty code' => [['pattern' => '{company}-{sequence}', 'company' => '']],
            'invalid code' => [['pattern' => '{company}-{sequence}', 'company' => 'bad/code']],
            'long code' => [['pattern' => '{company}-{sequence}', 'company' => str_repeat('A', 33)]],
            'non string code' => [['pattern' => '{company}-{sequence}', 'company' => ['EXAMPLE']]],
            'non string pattern' => [['pattern' => ['{sequence}']]],
            'unknown option' => [['pattern' => 'TEST-{sequence}', 'reset_policy' => 'annual']],
            'unsafe literal' => [['pattern' => 'TEST<script>{sequence}']],
            'oversized pattern' => [['pattern' => str_repeat('A', 257).'{sequence}']],
            'oversized number' => [['pattern' => str_repeat('A', 128).'{sequence}']],
            'leading separator' => [['pattern' => '-{sequence}']],
        ];
    }
}
