<?php

namespace App\Console\Commands;

use App\Authorization\DocumentFileBackup;
use App\Models\StorageEvent;
use Illuminate\Console\Command;
use Throwable;

class RestoreDocumentFiles extends Command
{
    protected $signature = 'dcms:files-restore {archive : Private backup ZIP matching the restored database}';

    protected $description = 'Restore missing or corrupt files after verifying them against database hashes';

    public function handle(DocumentFileBackup $backup): int
    {
        try {
            $count = $backup->restore($this->argument('archive'));
            StorageEvent::query()->create(['action' => 'files.restored', 'reason' => "CLI recovery: {$count} objects"]);
            $this->info("Restored {$count} file versions; database records were preserved.");

            return self::SUCCESS;
        } catch (Throwable $failure) {
            report($failure);
            $this->error('Private file operation failed. Review the protected application log.');

            return self::FAILURE;
        }
    }
}
