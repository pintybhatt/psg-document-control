<?php

namespace App\Console\Commands;

use App\Authorization\AccessControl;
use App\Authorization\ManageDocumentMasterData;
use App\Authorization\ManageNumbering;
use App\Authorization\NumberingFormat;
use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\NumberingSeries;
use App\Models\RegisterConfiguration;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Signature('dev:configure-demo-documents {--operator= : Operator responsible for this synthetic setup}')]
#[Description('Local SQLite only: configure existing DEMO-SITE master data and internal numbering without issuance')]
class ConfigureDemoDocuments extends Command
{
    private const CATEGORIES = [
        'ADM' => ['Administration', 'ບໍລິຫານ'],
        'HR' => ['Human Resources', 'ຊັບພະຍາກອນມະນຸດ'],
        'MIN' => ['Mining Operations', 'ການດຳເນີນງານບໍ່ແຮ່'],
        'FIN' => ['Finance', 'ການເງິນ'],
        'PRJ' => ['Project', 'ໂຄງການ'],
        'GEO' => ['Geology', 'ທໍລະນີສາດ'],
        'SUR' => ['Survey', 'ສຳຫຼວດ'],
    ];

    private const TYPES = [
        'LET' => ['Letter', 'ຈົດໝາຍ', ['incoming', 'outgoing']],
        'NOT' => ['Notice', 'ແຈ້ງການ', ['incoming', 'outgoing']],
        'MEM' => ['Memorandum', 'ບັນທຶກຂໍ້ຄວາມ', ['incoming', 'outgoing']],
        'RPT' => ['Report', 'ບົດລາຍງານ', ['incoming', 'outgoing', 'controlled']],
        'PRO' => ['Procedure', 'ຂັ້ນຕອນປະຕິບັດ', ['controlled']],
        'SOP' => ['SOP', 'ລະບຽບປະຕິບັດງານມາດຕະຖານ', ['controlled']],
        'FRM' => ['Form', 'ແບບຟອມ', ['controlled']],
        'DWG' => ['Drawing', 'ແບບແຕ້ມ', ['incoming', 'outgoing', 'controlled']],
    ];

    public function handle(AccessControl $access, ManageDocumentMasterData $master, ManageNumbering $numbering): int
    {
        if (! app()->environment('local') || DB::getDriverName() !== 'sqlite') {
            $this->error('Requires the local environment and SQLite.');

            return self::FAILURE;
        }
        $operator = trim((string) $this->option('operator'));
        if ($operator === '' || mb_strlen($operator) > 255) {
            $this->error('Supply --operator with a name of at most 255 characters.');

            return self::FAILURE;
        }
        try {
            $summary = DB::transaction(function () use ($access, $master, $numbering, $operator): array {
                $company = Company::query()->where('code', 'DEMO')->first();
                $site = $company?->sites()->where('code', 'DEMO-SITE')->first();
                if (! $company?->active || ! $site?->active || $company->name_en !== 'DEMO synthetic development company' || $site->name_en !== 'DEMO-SITE synthetic development site') {
                    $this->invalid('The existing active synthetic DEMO / DEMO-SITE identity is required; no organization will be created or changed.');
                }
                $scope = AuthorizationScope::context('site', $company->id, $site->id);
                if (! $scope->exists) {
                    $this->invalid('The existing DEMO-SITE authorization scope is required.');
                }
                $actor = User::query()->where('email', 'admin@dcms.test')->where('active', true)->first();
                if (! $actor || ! $access->isSystemAdministrator($actor)) {
                    $this->invalid('An existing active admin@dcms.test Security Administrator is required; no authority will be granted.');
                }
                $summary = ['registers_created' => 0, 'categories_created' => 0, 'types_created' => 0, 'type_links_updated' => 0, 'series_created' => 0];
                $registers = [];
                foreach (['incoming' => 'INC', 'outgoing' => 'OUT', 'controlled' => 'CTL'] as $key => $prefix) {
                    $register = RegisterConfiguration::query()->where('scope_id', $scope->id)->where('register_key', $key)->first();
                    if (! $register) {
                        $register = $master->save($actor, $scope, 'registers', ['register_key' => $key, 'active' => true, 'description_en' => 'Synthetic DEMO register. Operator: '.$operator, 'description_lo' => 'ທະບຽນທົດລອງ DEMO']);
                        $summary['registers_created']++;
                    }
                    if (! $register->active) {
                        $this->invalid('An existing DEMO register is inactive; review it in Administration.');
                    }
                    $registers[$key] = $register->id;
                    $series = NumberingSeries::query()->where('register_id', $register->id)->where('purpose', 'internal_register')->first();
                    if ($series && $series->reset_policy->value !== 'annual') {
                        $this->invalid('An existing DEMO series uses a different immutable reset policy; nothing will be overwritten.');
                    }
                    $payload = ['prefix' => $prefix, 'padding' => 3, 'active' => true, 'change_reason' => 'Synthetic DEMO configuration by '.$operator.'; no numbers issued.'];
                    if (! $series) {
                        $payload += ['register_id' => $register->id, 'purpose' => 'internal_register', 'reset_policy' => 'annual'];
                    }
                    $numbering->save($actor, $scope, $payload, $series ? (string) $series->id : null);
                    $summary['series_created'] += $series ? 0 : 1;
                }
                foreach (self::CATEGORIES as $code => [$english, $lao]) {
                    $category = DocumentCategory::query()->where('scope_id', $scope->id)->where('code', $code)->first();
                    if (! $category) {
                        if (DocumentCategory::query()->where('scope_id', $scope->id)->whereRaw('LOWER(name_en) = ?', [mb_strtolower($english)])->exists()) {
                            $this->invalid('A DEMO category name already exists with another immutable code; review it before rerunning.');
                        }
                        $category = $master->save($actor, $scope, 'categories', ['code' => $code, 'name_en' => $english, 'name_lo' => $lao, 'active' => true]);
                        $summary['categories_created']++;
                    }
                    if (! $category->active || mb_strtolower($category->name_en) !== mb_strtolower($english)) {
                        $this->invalid('An existing DEMO category conflicts with the fixture; nothing will be overwritten.');
                    }
                    foreach (self::TYPES as $suffix => [$name, $translation, $keys]) {
                        if ($suffix === 'DWG' && ! in_array($code, ['MIN', 'PRJ', 'GEO', 'SUR'], true)) {
                            continue;
                        }
                        $typeCode = $code.'-'.$suffix;
                        $type = DocumentType::query()->where('scope_id', $scope->id)->where('code', $typeCode)->first();
                        $ids = array_map(fn (string $key): int => $registers[$key], $keys);
                        if (! $type) {
                            $type = DocumentType::query()->where('scope_id', $scope->id)->where('category_id', $category->id)->whereRaw('LOWER(name_en) = ?', [mb_strtolower($name)])->first();
                        }
                        if ($type) {
                            if (! $type->active || $type->category_id !== $category->id || mb_strtolower($type->name_en) !== mb_strtolower($name)) {
                                $this->invalid('An existing DEMO type conflicts with the fixture; nothing will be overwritten.');
                            }
                            $existingIds = $type->registers()->pluck('register_configurations.id')->all();
                            if (array_diff($ids, $existingIds) !== []) {
                                $master->save($actor, $scope, 'types', ['register_ids' => array_values(array_unique([...$existingIds, ...$ids]))], (string) $type->id);
                                $summary['type_links_updated']++;
                            }
                        } else {
                            $master->save($actor, $scope, 'types', ['code' => $typeCode, 'name_en' => $name, 'name_lo' => $translation, 'category_id' => $category->id, 'register_ids' => $ids, 'active' => true]);
                            $summary['types_created']++;
                        }
                    }
                }

                return $summary;
            }, 1);
        } catch (ValidationException $failure) {
            $this->error($failure->errors()[array_key_first($failure->errors())][0]);

            return self::FAILURE;
        }
        foreach ($summary as $label => $count) {
            $this->line($label.': '.$count);
        }
        $this->info('Configured existing DEMO-SITE only. Internal formats: '.implode(', ', array_map(fn (string $prefix): string => NumberingFormat::render($prefix, 3, (int) now()->format('Y'), 1), ['INC', 'OUT', 'CTL'])));
        $this->info('No documents, counters, issued numbers, official outgoing configuration or role assignments were created or changed.');

        return self::SUCCESS;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['demo' => $message]);
    }
}
