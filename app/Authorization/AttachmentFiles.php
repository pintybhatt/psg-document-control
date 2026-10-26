<?php

namespace App\Authorization;

use App\Models\AttachmentVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class AttachmentFiles
{
    /** @return array{original_name: string, mime_type: string, size: int, sha256: string} */
    public function inspect(UploadedFile $file): array
    {
        $name = $file->getClientOriginalPath();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $validName = mb_check_encoding($name, 'UTF-8') && mb_strlen($name) <= 200
            && ! preg_match('/[\x00-\x1f\x7f\\\\\/:<>"|?*\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', $name)
            && ! str_contains($name, '..') && ! preg_match('/\.(php\d*|phtml|phar|exe|com|bat|cmd|js|html?|svg|sh|ps1)\./i', $name);
        if (! $validName || ! in_array($extension, config('document_storage.extensions'), true) || ! $file->isValid()
            || $file->getSize() < 1 || $file->getSize() > config('document_storage.max_upload_kb') * 1024) {
            $this->invalid();
        }
        $path = $file->getRealPath();
        $stream = fopen($path, 'rb');
        $head = fread($stream, 512);
        fclose($stream);
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $mime = match ($extension) {
            'pdf' => str_starts_with($head, '%PDF-') && $detected === 'application/pdf' ? 'application/pdf' : null,
            'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff' => $this->imageType($path, $extension),
            'docx', 'xlsx' => $this->officeType($path, $extension),
            'doc', 'xls' => str_starts_with($head, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1") && in_array($detected, ['application/msword', 'application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/x-cdf'], true)
                ? ($extension === 'doc' ? 'application/msword' : 'application/vnd.ms-excel') : null,
            'dwg' => preg_match('/^AC10[0-9]{2}/', $head) ? 'application/acad' : null,
            'dxf' => str_starts_with($head, "AutoCAD Binary DXF\r\n\x1a\0") || preg_match('/^\s*0\s+SECTION\s+2\s+(HEADER|ENTITIES|TABLES|BLOCKS)/', $head) ? 'image/vnd.dxf' : null,
            default => null,
        };
        if ($mime === null) {
            $this->invalid();
        }

        return ['original_name' => $name, 'mime_type' => $mime, 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $path)];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['file' => 'storageInvalidFile']);
    }

    private function imageType(string $path, string $extension): ?string
    {
        $image = @getimagesize($path);
        $expected = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', default => 'image/tiff',
        };

        return $image && $image['mime'] === $expected && $image[0] * $image[1] <= 40000000 ? $expected : null;
    }

    private function officeType(string $path, string $extension): ?string
    {
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            if ($archive->numFiles > 5000 || $archive->locateName('[Content_Types].xml') === false
                || $archive->locateName($extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml') === false) {
                return null;
            }
            $expanded = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $expanded += $entry['size'];
                if ($expanded > 250 * 1024 * 1024 || preg_match('/(vbaProject|embeddings\/|\.\.|\.(exe|js|php|html|vbs)$)/i', $entry['name'])) {
                    return null;
                }
            }

            return $extension === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        } finally {
            $archive->close();
        }
    }

    public function key(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('Invalid private object identity.');
        }

        return 'objects/'.$id;
    }

    /** @return resource */
    public function verifiedStream(AttachmentVersion $version): mixed
    {
        $stream = Storage::disk('documents')->readStream($this->key($version->id));
        if (! is_resource($stream)) {
            throw new RuntimeException('Attachment unavailable.');
        }
        $hash = hash_init('sha256');
        $size = hash_update_stream($hash, $stream);
        if ($size !== $version->size || ! hash_equals($version->sha256, hash_final($hash))) {
            fclose($stream);
            throw new RuntimeException('Attachment integrity failure.');
        }
        rewind($stream);

        return $stream;
    }
}
