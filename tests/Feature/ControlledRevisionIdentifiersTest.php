<?php

namespace Tests\Feature;

use App\Models\ControlledRevision;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ControlledRevisionIdentifiersTest extends TestCase
{
    #[DataProvider('identifiers')]
    public function test_labels_and_display_identifiers_preserve_stored_codes(int $number, string $code, string $label, ?string $display): void
    {
        $revision = ControlledRevision::factory()->make(['revision_number' => $number, 'document_id' => 1, 'owner_id' => 1, 'custodian_id' => 1, 'created_by' => 1, 'updated_by' => 1]);
        $this->assertSame($label, $revision->identifier());
        $this->assertSame($display, $revision->displayIdentifier($code));
        $this->assertSame($number, $revision->revision_number);
    }

    /** @return array<string, array{int, string, string, string|null}> */
    public static function identifiers(): array
    {
        return [
            'initial' => [0, 'EXAMPLE-ADM-SOP-0001', 'R00', 'EXAMPLE-ADM-SOP-0001-R00'],
            'first revision' => [1, 'EXAMPLE-ADM-SOP-0001', 'R01', 'EXAMPLE-ADM-SOP-0001-R01'],
            'second revision' => [2, 'EXAMPLE-ADM-SOP-0001', 'R02', 'EXAMPLE-ADM-SOP-0001-R02'],
            'legacy slashes' => [2, 'LEGACY/SOP/001', 'R02', 'LEGACY/SOP/001-R02'],
            'minimum padding' => [100, 'LEGACY/SOP/001', 'R100', 'LEGACY/SOP/001-R100'],
            'matching suffix' => [0, 'LEGACY-SOP-001-R00', 'R00', 'LEGACY-SOP-001-R00'],
            'matching later suffix' => [2, 'LEGACY-SOP-001-R02', 'R02', 'LEGACY-SOP-001-R02'],
            'static suffix on later revision' => [1, 'LEGACY-SOP-001-R00', 'R01', null],
            'different suffix' => [0, 'LEGACY-SOP-001-R07', 'R00', null],
            'multiple suffixes' => [0, 'LEGACY-R01-R00', 'R00', null],
            'embedded revision component' => [0, 'LEGACY-R00-SOP-001', 'R00', null],
            'unconfirmed separator' => [0, 'LEGACY/SOP/001/R00', 'R00', null],
            'legacy lowercase suffix' => [0, 'LEGACY-SOP-001-r00', 'R00', null],
            'unconfirmed short suffix' => [0, 'LEGACY-SOP-001-R0', 'R00', null],
            'suffix alone' => [0, 'R00', 'R00', null],
            'attached suffix' => [0, 'LEGACY001R00', 'R00', null],
        ];
    }
}
