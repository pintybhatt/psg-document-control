<?php

namespace App\Http\Controllers;

use App\Authorization\AdministrationHistory;
use App\Http\Requests\RbacScopeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AdministrationHistoryController extends Controller
{
    public function __invoke(RbacScopeRequest $request, AdministrationHistory $history): JsonResponse
    {
        $data = $request->validate(['source' => ['required', Rule::in(array_keys(AdministrationHistory::SOURCES))]]);
        $scope = $request->scopeContext();
        $records = $history->query($request->user(), $scope, $data['source'])->paginate($request->integer('per_page', 10));
        $records->through(function (object $record): array {
            $fields = ['code', 'name_en', 'name_lo', 'active', 'prefix', 'padding', 'version', 'change_reason', 'permission_keys', 'register_ids', 'user_id', 'role_id'];
            $before = json_decode($record->before ?? '{}', true) ?: [];
            $after = json_decode($record->after ?? '{}', true) ?: [];
            $changes = [];
            foreach ($fields as $field) {
                $old = $before[$field] ?? null;
                $new = $after[$field] ?? ($record->{$field} ?? null);
                if ($field === 'active') {
                    $old = $old === null ? null : (bool) $old;
                    $new = $new === null ? null : (bool) $new;
                }
                $safe = fn (mixed $value): bool => $value === null || is_scalar($value) || (is_array($value) && array_is_list($value) && count(array_filter($value, fn (mixed $item): bool => ! is_string($item) && ! is_int($item))) === 0);
                if ($old !== $new && $safe($old) && $safe($new)) {
                    $changes[] = ['field' => $field, 'before' => $old, 'after' => $new];
                }
            }

            return [
                'id' => $record->id, 'timestamp' => Carbon::parse($record->created_at, 'UTC')->toISOString(),
                'actor' => $record->actor, 'subject_type' => $record->subject_type, 'subject_id' => $record->subject_id,
                'action' => $record->action, 'changes' => $changes,
            ];
        });

        return response()->json(['data' => $records->items(), 'meta' => ['total' => $records->total()], 'sources' => $history->sources($request->user(), $scope)]);
    }
}
