<?php

namespace App\Authorization;

use App\Models\AttachmentVersion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class DocumentFileBackup
{
    public function __construct(private AttachmentFiles $files) {}

    /** @return array{checked: int, failed: int} */
    public function verify(): array
    {
        $checked = $failed = 0;
        foreach (AttachmentVersion::query()->orderBy('id')->lazy() as $version) {
            $checked++;
            try {
                fclose($this->files->verifiedStream($version));
            } catch (Throwable) {
                $failed++;
            }
        }

        return ['checked' => $checked, 'failed' => $failed];
    }

    /** The destination must be private and outside the web root; never overwrite a backup. */
    public function backup(string $destination): int
    {
        $directory = realpath(dirname($destination));
        $public = realpath(public_path());
        if ($directory === false || str_starts_with($directory.'/', $public.'/') || is_file($destination)) {
            throw new RuntimeException('Choose a new archive in an existing private directory outside public/.');
        }
        $placeholder = fopen($destination, 'x+b');
        if ($placeholder === false) {
            throw new RuntimeException('Cannot reserve backup destination.');
        }
        chmod($destination, 0600);
        fclose($placeholder);
        $archive = new ZipArchive;
        if ($archive->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create backup.');
        }
        try {
            $manifest = [];
            foreach (AttachmentVersion::query()->orderBy('id')->lazy() as $version) {
                $stream = $this->files->verifiedStream($version);
                try {
                    $bytes = stream_get_contents($stream);
                } finally {
                    fclose($stream);
                }
                if (! $archive->addFromString($this->files->key($version->id), $bytes)) {
                    throw new RuntimeException('Cannot write backup object.');
                }
                $manifest[] = $version->only(['id', 'attachment_id', 'version', 'size', 'sha256']);
            }
            if (! $archive->addFromString('manifest.json', json_encode(['format' => 1, 'created_at' => now()->toISOString(), 'versions' => $manifest], JSON_THROW_ON_ERROR)) || ! $archive->close()) {
                throw new RuntimeException('Cannot finalize backup.');
            }

            return count($manifest);
        } catch (Throwable $failure) {
            @$archive->close();
            @unlink($destination);
            throw $failure;
        }
    }

    /** Restore only objects referenced by this database; never extract untrusted archive paths. */
    public function restore(string $source): int
    {
        $archive = new ZipArchive;
        if ($archive->open($source, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Cannot open backup.');
        }
        $restored = 0;
        try {
            foreach (AttachmentVersion::query()->orderBy('id')->lazy() as $version) {
                try {
                    fclose($this->files->verifiedStream($version));

                    continue;
                } catch (Throwable) {
                }
                $key = $this->files->key($version->id);
                $entry = $archive->statName($key);
                if (! $entry || $entry['size'] !== $version->size) {
                    throw new RuntimeException('Backup does not contain the required file version.');
                }
                $stream = $archive->getStream($key);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Backup object cannot be read.');
                }
                $temporary = tmpfile();
                try {
                    stream_copy_to_stream($stream, $temporary);
                    rewind($temporary);
                    $hash = hash_init('sha256');
                    $size = hash_update_stream($hash, $temporary);
                    if ($size !== $version->size || ! hash_equals($version->sha256, hash_final($hash))) {
                        throw new RuntimeException('Backup integrity failure.');
                    }
                    rewind($temporary);
                    if (! Storage::disk('documents')->put($key, $temporary, ['visibility' => 'private'])) {
                        throw new RuntimeException('Restore write failed.');
                    }
                } finally {
                    fclose($stream);
                    fclose($temporary);
                }
                fclose($this->files->verifiedStream($version));
                $restored++;
            }

            return $restored;
        } finally {
            $archive->close();
        }
    }
}
