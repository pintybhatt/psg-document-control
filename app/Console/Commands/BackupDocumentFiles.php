<?php

namespace App\Console\Commands;

use App\Authorization\DocumentFileBackup;
use Illuminate\Console\Command;
use Throwable;

class BackupDocumentFiles extends Command
{
    protected $signature = 'dcms:files-backup {destination : New private ZIP archive outside public/}';

    protected $description = 'Create a verified private attachment backup with a consistency manifest';

    public function handle(DocumentFileBackup $backup): int
    {
        try {
            $count = $backup->backup($this->argument('destination'));
            $this->info("Backed up {$count} file versions. Back up the database at the same maintenance checkpoint.");

            return self::SUCCESS;
        } catch (Throwable $failure) {
            report($failure);
            $this->error('Private file operation failed. Review the protected application log.');

            return self::FAILURE;
        }
    }
}
