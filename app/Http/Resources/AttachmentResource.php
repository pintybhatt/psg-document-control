<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'revision_id' => $this->revision_id, 'versions' => $this->versions->map(fn ($version): array => $version->only(['id', 'version', 'original_name', 'mime_type', 'size', 'uploaded_by', 'reason']) + ['uploaded_at' => $version->created_at->toISOString(), 'previewable' => in_array($version->mime_type, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)])->values()];
    }
}
