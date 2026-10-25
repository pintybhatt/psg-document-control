<?php

namespace App\Authorization;

use Illuminate\Support\Facades\Validator;

class NumberingFormat
{
    public const MAX_SEQUENCE = 999999999999;

    public const PREFIX_PATTERN = '/^[A-Z][A-Z0-9_-]*(\/[A-Z0-9][A-Z0-9_-]*)*$/D';

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['prefix' => ['required', 'string', 'max:80', 'regex:'.self::PREFIX_PATTERN],
            'padding' => ['required', 'integer', 'min:1', 'max:12']];
    }

    public static function render(string $prefix, int $padding, int $year, int $sequence): string
    {
        Validator::make(compact('prefix', 'padding', 'year', 'sequence'), self::rules() + [
            'year' => ['required', 'integer', 'between:2000,9999'],
            'sequence' => ['required', 'integer', 'min:1', 'max:'.self::MAX_SEQUENCE],
        ], array_fill_keys(['required', 'string', 'max', 'min', 'integer', 'between', 'regex'], 'numberingInvalidFormat'))->validate();

        return $prefix.'/'.$year.'/'.str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT);
    }
}
