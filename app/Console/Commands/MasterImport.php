<?php

namespace App\Console\Commands;

use App\Authorization\DeveloperMasterData;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

#[Signature('dcms:master-import {--file=database/master-data/xppl-master.json : Master configuration JSON} {--dry-run : Validate and show the merge plan without database writes} {--actor= : Existing active local user ID for new master creator fields}')]
#[Description('Local developer only: create missing XPPL masters, skip exact matches and reject conflicts')]
class MasterImport extends Command
{
    public function handle(DeveloperMasterData $master): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Developer master import is restricted to local/testing environments.');

            return self::FAILURE;
        }
        try {
            $file = (string) $this->option('file');
            if (! is_file($file) || filesize($file) > 2_000_000) {
                throw new RuntimeException('Supply a readable master JSON file smaller than 2 MB.');
            }
            $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_is_list($data)) {
                throw new RuntimeException('Master JSON must be an object.');
            }
            $actor = null;
            if ($this->option('actor') !== null) {
                $id = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
                $actor = $id ? User::query()->whereKey($id)->where('active', true)->first() : null;
                if (! $actor) {
                    throw new RuntimeException('--actor must identify an existing active local user; no users are imported.');
                }
            }
            $plan = $master->import($data, $actor, (bool) $this->option('dry-run'));
            $this->table(['Type', 'Business key', 'Action', 'Reason'], array_map(fn (array $row): array => array_values($row), $plan));
            foreach (collect($plan)->groupBy('kind') as $kind => $rows) {
                $counts = $rows->countBy('action');
                $this->line($kind.': create='.($counts['create'] ?? 0).', skip='.($counts['skip'] ?? 0).', conflict='.($counts['conflict'] ?? 0));
            }
            $conflicts = in_array('conflict', array_column($plan, 'action'), true);
            $this->info($this->option('dry-run') ? 'DRY RUN: no database writes.' : 'Import completed. Existing records and numbering state preserved.');

            return $conflicts ? self::FAILURE : self::SUCCESS;
        } catch (ValidationException $failure) {
            foreach ($failure->errors() as $key => $messages) {
                $this->error($key.': '.implode(' ', $messages));
            }

            return self::FAILURE;
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }
}
