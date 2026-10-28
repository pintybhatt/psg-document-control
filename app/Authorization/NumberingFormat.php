<?php

namespace App\Authorization;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class NumberingFormat
{
    public const MAX_SEQUENCE = 999999999999;

    public const PREFIX_PATTERN = '/^[A-Z][A-Z0-9_-]*(\/[A-Z0-9][A-Z0-9_-]*)*$/D';

    /** @param array{pattern: string, company?: string, department?: string, document_type?: string}|null $format */
    public static function validateResetPolicy(?array $format, NumberingResetPolicy $resetPolicy): void
    {
        if ($resetPolicy === NumberingResetPolicy::Annual && $format !== null
            && ! str_contains($format['pattern'] ?? '', '{year}') && ! str_contains($format['pattern'] ?? '', '{year2}')) {
            throw ValidationException::withMessages(['format' => 'numberingInvalidFormat']);
        }
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return ['prefix' => ['required', 'string', 'max:80', 'regex:'.self::PREFIX_PATTERN],
            'padding' => ['required', 'integer', 'min:1', 'max:12'],
            'format' => ['nullable', 'array:pattern,company,department,document_type', 'min:1'],
            'format.pattern' => ['required_with:format', 'string', 'max:256',
                'regex:/^(?:[A-Z0-9_\/.\-]|\{(?:prefix|company|department|document_type|year|year2|sequence)\})+$/D',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || substr_count($value, '{sequence}') !== 1) {
                        $fail('numberingInvalidFormat');
                    }
                }],
            'format.company' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^[A-Z][A-Z0-9_-]*$/D'],
            'format.department' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^[A-Z][A-Z0-9_-]*$/D'],
            'format.document_type' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^[A-Z][A-Z0-9_-]*$/D']];
    }

    /**
     * Patterns accept {prefix}, {company}, {department}, {document_type}, {year},
     * {year2}, and exactly one {sequence}; literals use A-Z, 0-9, -, _, /, or .
     * Codes are configuration snapshots, not sequence partitions. A literal suffix
     * such as R00 has no connection to the controlled-document revision lifecycle.
     *
     * @param  array{pattern: string, company?: string, department?: string, document_type?: string}|null  $format
     */
    public static function render(string $prefix, int $padding, int $year, int $sequence, ?array $format = null): string
    {
        Validator::make(compact('prefix', 'padding', 'year', 'sequence', 'format'), self::rules() + [
            'year' => ['required', 'integer', 'between:2000,9999'],
            'sequence' => ['required', 'integer', 'min:1', 'max:'.self::MAX_SEQUENCE],
        ], array_fill_keys(['required', 'string', 'max', 'min', 'integer', 'between', 'regex'], 'numberingInvalidFormat'))->validate();

        $sequenceText = str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT);
        if ($format === null) {
            return $prefix.'/'.$year.'/'.$sequenceText;
        }
        $components = ['{prefix}' => $prefix, '{year}' => (string) $year, '{year2}' => substr((string) $year, -2), '{sequence}' => $sequenceText];
        foreach (['company', 'department', 'document_type'] as $code) {
            if (str_contains($format['pattern'], '{'.$code.'}') && ! isset($format[$code])) {
                throw ValidationException::withMessages(['format' => 'numberingInvalidFormat']);
            }
            $components['{'.$code.'}'] = $format[$code] ?? '';
        }
        $number = strtr($format['pattern'], $components);
        if (strlen($number) > 128 || ! preg_match('/^[A-Z0-9][A-Z0-9_\/.\-]*$/D', $number)) {
            throw ValidationException::withMessages(['format' => 'numberingInvalidFormat']);
        }

        return $number;
    }
}
