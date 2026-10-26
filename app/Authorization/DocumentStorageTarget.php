<?php

namespace App\Authorization;

use App\Models\ControlledDocument;
use App\Models\ControlledRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentStorageTarget
{
    public function __construct(private ManageIncomingCorrespondence $incoming, private ManageOutgoingCorrespondence $outgoing, private ManageControlledDocuments $controlled, private AccessControl $roles, private DocumentAccess $access) {}

    public function find(User $actor, DocumentScope $scope, string $type, string $id, ?string $permission = null, bool $lock = false): Model
    {
        $manager = match ($type) {
            'incoming' => $this->incoming, 'outgoing' => $this->outgoing, 'controlled' => $this->controlled, default => abort(404),
        };
        $manager->authorizeContext($actor, $scope, $permission);
        if ($lock && DB::getDriverName() === 'sqlite') {
            DB::table('register_configurations')->where('scope_id', $scope->authorizationScope()->id)->where('register_key', $type)->update(['id' => DB::raw('id')]);
        }

        return $manager->find($actor, $scope, $id, $lock);
    }

    public function content(User $actor, Model $record, DocumentOperation $operation): void
    {
        abort_unless($this->access->allows($actor, DocumentSecurityDescriptor::fromProfile($record->profile), $operation), 404);
    }

    public function revision(Model $record, ?int $id, bool $writable = false): ?ControlledRevision
    {
        if (! $record instanceof ControlledDocument) {
            abort_if($id !== null, 404);

            return null;
        }
        $revision = $record->revisions()->whereKey($id)->firstOrFail();
        if ($writable && ($revision->released_at !== null || ! in_array($record->status, ['draft', 'current'], true))) {
            throw ValidationException::withMessages(['storage' => 'storageRevisionImmutable']);
        }

        return $revision;
    }
}
