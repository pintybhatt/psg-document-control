<?php

namespace App\Console\Commands;

use App\Authorization\DocumentFileBackup;
use Illuminate\Console\Command;
use Throwable;

class VerifyDocumentFiles extends Command
{
    protected $signature = 'dcms:files-verify';

    protected $description = 'Verify private file presence, size and SHA-256 integrity';

    public function handle(DocumentFileBackup $backup): int
    {
        try {
            $result = $backup->verify();
            $this->line("Checked: {$result['checked']}; failed: {$result['failed']}");

            return $result['failed'] ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $failure) {
            report($failure);
            $this->error('Private file operation failed. Review the protected application log.');

            return self::FAILURE;
        }
    }
}
