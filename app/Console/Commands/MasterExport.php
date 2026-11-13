<?php

namespace App\Console\Commands;

use App\Authorization\DeveloperMasterData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

#[Signature('dcms:master-export {--company=XPPL : Company business code} {--file=database/master-data/xppl-master.json : Output JSON path}')]
#[Description('Export local XPPL master configuration without users, documents or numbering state')]
class MasterExport extends Command
{
    public function handle(DeveloperMasterData $master): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Developer master export is restricted to local/testing environments.');

            return self::FAILURE;
        }
        try {
            if ($this->option('company') !== 'XPPL') {
                throw new \RuntimeException('Only XPPL master configuration is supported.');
            }
            $data = DB::transaction(fn (): array => $master->export('XPPL'));
            $path = (string) $this->option('file');
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $json);
            $this->info('Exported XPPL configuration: '.$path);
            foreach ($data as $kind => $rows) {
                if (is_array($rows)) {
                    $this->line($kind.': '.($kind === 'company' ? 1 : count($rows)));
                }
            }
            $this->warn('DEV snapshot for owner review; this command does not certify UAT equality or official codes/translations.');

            return self::SUCCESS;
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
    }
}
