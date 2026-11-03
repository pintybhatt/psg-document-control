<?php

namespace App\Console\Commands;

use App\Authorization\AccessControl;
use App\Authorization\ManageDocumentMasterData;
use App\Authorization\ManageOrganization;
use App\Http\Requests\DocumentMasterDataRequest;
use App\Models\AuthorizationScope;
use App\Models\Company;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\DocumentType;
use App\Models\RegisterConfiguration;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use SQLite3;
use Throwable;

#[Signature('dev:prepare-xppl-master-data {--apply : Insert missing rows only after Product Owner approval} {--approve= : Approved proposal SHA-256 printed by dry-run} {--actor= : Existing authorized administrator ID} {--backup= : New absolute SQLite backup file path required for apply}')]
#[Description('Local DEV SQLite only: review XPPL master-data proposals; default mode never writes')]
class PrepareXpplMasterData extends Command
{
    public function handle(AccessControl $access, ManageOrganization $organization, ManageDocumentMasterData $master): int
    {
        try {
            $database = realpath(DB::connection()->getDatabaseName());
            if (! app()->environment('local') || DB::getDriverName() !== 'sqlite' || ! $database || $database !== realpath(database_path('database.sqlite'))) {
                throw new RuntimeException('Requires local DEV database/database.sqlite; no other connection or environment is allowed.');
            }
            $file = base_path('database/seeders/xppl-master-data.json');
            $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $hash = hash_file('sha256', $file);
            $company = Company::query()->whereKey(2)->where('code', 'XPPL')->first();
            $site = $company?->sites()->whereKey(2)->where('code', 'XPPL')->first();
            if (! $company?->active || ! $site?->active) {
                throw new RuntimeException('Existing active XPPL company/site ID 2 required; neither will be created or changed.');
            }
            $scope = AuthorizationScope::context('site', 2, 2);
            $plan = $this->plan($data, $scope);
            $this->line('Proposal SHA-256: '.$hash);
            $this->warn('PROPOSAL: CAT codes, type/category/register mappings and [DEV-UNTRANSLATED] English text in name_lo require Product Owner approval.');
            $this->table(['Kind', 'Code', 'English name', 'Action', 'Proposed category / registers'], $plan);
            foreach (['departments', 'categories', 'registers', 'types'] as $kind) {
                $rows = array_filter($plan, fn (array $row): bool => $row[0] === $kind);
                $counts = array_count_values(array_column($rows, 3));
                $this->line($kind.': create='.($counts['create'] ?? 0).', skip='.($counts['skip'] ?? 0).', conflict='.($counts['conflict'] ?? 0));
            }
            if (in_array('conflict', array_column($plan, 3), true)) {
                throw new RuntimeException('Conflicting names, codes, active status or relationships; nothing will be overwritten.');
            }
            if (! $this->option('apply')) {
                $this->info('DRY RUN: 0 inserted; existing rows and all numbering data unchanged. No backup or authority changes made.');

                return self::SUCCESS;
            }
            if (! hash_equals($hash, (string) $this->option('approve'))) {
                throw new RuntimeException('Apply requires explicit approval of the exact proposal SHA-256, including the DEV bilingual approach.');
            }
            $actor = User::query()->whereKey((int) $this->option('actor'))->where('active', true)->first();
            if (! $actor) {
                throw new RuntimeException('Supply an existing active administrator with --actor.');
            }
            $organization->authorize($actor);
            $access->authorize($actor, 'master-data.manage', $scope);
            DB::transaction(function () use ($company, $data, $scope, $database, $actor, $organization, $master): void {
                DB::table('companies')->where('id', $company->id)->update(['id' => DB::raw('id')]);
                if (in_array('conflict', array_column($this->plan($data, $scope), 3), true)) {
                    throw new RuntimeException('Master data changed; rerun dry-run.');
                }
                $this->backup($database);
                foreach ($data['departments'] as $row) {
                    if (! Department::query()->where('site_id', 2)->where('code', $row['code'])->exists()) {
                        $organization->save($actor, 'departments', $row + ['company_id' => 2, 'site_id' => 2]);
                    }
                }
                foreach (['registers', 'categories', 'types'] as $kind) {
                    foreach ($data[$kind] as $row) {
                        $key = $kind === 'registers' ? 'register_key' : 'code';
                        if ($master->query($kind, $scope)->where($key, $row[$key])->exists()) {
                            continue;
                        }
                        if ($kind === 'types') {
                            $row['category_id'] = DocumentCategory::query()->where('scope_id', $scope->id)->where('code', $row['category_code'])->sole()->id;
                            $row['register_ids'] = RegisterConfiguration::query()->where('scope_id', $scope->id)->whereIn('register_key', $row['register_keys'])->pluck('id')->all();
                            unset($row['category_code'], $row['register_keys']);
                        }
                        $this->validateMaster($kind, $row);
                        $master->save($actor, $scope, $kind, $row);
                        $scope = AuthorizationScope::context('site', 2, 2);
                    }
                    $scope = AuthorizationScope::context('site', 2, 2);
                }
                if (in_array('create', array_column($this->plan($data, $scope), 3), true) || in_array('conflict', array_column($this->plan($data, $scope), 3), true)) {
                    throw new RuntimeException('Post-import verification failed; entire import rolled back.');
                }
            }, 1);
            $this->info('Applied missing XPPL master data only. Existing rows, DEMO and numbering data unchanged.');

            return self::SUCCESS;
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $data
     * @return list<array{string, string, string, string, string}>
     */
    private function plan(array $data, AuthorizationScope $scope): array
    {
        $plan = [];
        foreach (['departments' => Department::class, 'categories' => DocumentCategory::class, 'registers' => RegisterConfiguration::class, 'types' => DocumentType::class] as $kind => $class) {
            foreach ($data[$kind] as $row) {
                $key = $kind === 'registers' ? 'register_key' : 'code';
                $query = $class::query()->where($kind === 'departments' ? 'site_id' : 'scope_id', $kind === 'departments' ? 2 : $scope->id);
                $existing = (clone $query)->where($key, $row[$key])->first();
                $conflict = $existing && (! $existing->active || (isset($row['name_en']) && $existing->name_en !== $row['name_en']));
                if (! $existing && isset($row['name_en']) && (clone $query)->whereRaw('LOWER(name_en) = ?', [mb_strtolower($row['name_en'])])->exists()) {
                    $conflict = true;
                }
                if ($existing && $kind === 'types') {
                    $keys = $existing->registers()->pluck('register_key')->map(fn ($key): string => $key instanceof \BackedEnum ? $key->value : $key)->all();
                    sort($keys);
                    $expected = $row['register_keys'];
                    sort($expected);
                    $conflict = $conflict || $existing->category->code !== $row['category_code'] || $keys !== $expected || ! $existing->category->active || $existing->registers->contains(fn ($register): bool => ! $register->active);
                }
                $plan[] = [$kind, $row[$key], $row['name_en'] ?? $row[$key], $conflict ? 'conflict' : ($existing ? 'skip' : 'create'), ($row['category_code'] ?? '').' / '.implode(',', $row['register_keys'] ?? [])];
            }
        }

        return $plan;
    }

    /** @param array<string, mixed> $row */
    private function validateMaster(string $kind, array $row): void
    {
        $request = DocumentMasterDataRequest::create('/document-master-data/'.$kind, 'POST', $row + ['scope_type' => 'site', 'company_id' => 2, 'site_id' => 2]);
        $route = new Route('POST', 'document-master-data/{kind}', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn (): Route => $route);
        Validator::make($request->all(), $request->rules(), $request->messages())->validate();
    }

    private function backup(string $database): void
    {
        $path = (string) $this->option('backup');
        if (! str_starts_with($path, '/') || ! is_dir(dirname($path)) || file_exists($path) || is_link($path) || str_starts_with($path, public_path().'/')) {
            throw new RuntimeException('Supply --backup with a new absolute file outside public/, in an existing directory.');
        }
        $file = fopen($path, 'x');
        chmod($path, 0600);
        fclose($file);
        $source = new SQLite3($database, SQLITE3_OPEN_READONLY);
        $destination = new SQLite3($path, SQLITE3_OPEN_READWRITE);
        try {
            if (! $source->backup($destination) || $destination->querySingle('PRAGMA integrity_check') !== 'ok') {
                throw new RuntimeException('SQLite backup verification failed; import refused.');
            }
        } finally {
            $destination->close();
            $source->close();
        }
        $this->line('Verified pre-import SQLite backup: '.$path);
    }
}
