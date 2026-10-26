<?php

namespace App\Http\Controllers;

use App\Authorization\CorrespondenceContexts;
use App\Authorization\DocumentScope;
use App\Authorization\ManageIncomingCorrespondence;
use App\Authorization\ManageOutgoingCorrespondence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CorrespondenceContexts $contexts, ManageIncomingCorrespondence $incoming, ManageOutgoingCorrespondence $outgoing): JsonResponse
    {
        $validated = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'site_id' => ['required', 'integer', 'min:1']]);
        $eligible = collect($contexts->forUser($request->user()))->filter(fn (array $context): bool => $context['company_id'] === (int) $validated['company_id'] && $context['site_id'] === (int) $validated['site_id']);
        abort_if($eligible->isEmpty(), 403, 'rbacForbidden');
        $totals = ['incoming' => 0, 'outgoing' => 0, 'due_soon' => 0, 'overdue' => 0];
        $recent = [];
        $due = [];
        $quickActions = [];
        $today = today()->format('Y-m-d');
        $soon = today()->addDays(7)->format('Y-m-d');
        foreach ($eligible as $context) {
            $scope = new DocumentScope($context['company_id'], $context['site_id'], $context['department_id']);
            foreach (['incoming' => $incoming, 'outgoing' => $outgoing] as $kind => $manager) {
                $manager->authorizeContext($request->user(), $scope);
                if (in_array($kind.'.drafts.manage', $context['permissions'], true)) {
                    $quickActions[$kind] = ['kind' => $kind, 'href' => route('dcms.'.$kind, ['unit' => $context['key'], 'create' => 1], false)];
                }
                foreach ($manager->query($scope)->with(['profile', 'operation'])->orderByDesc('id')->lazy() as $record) {
                    // Counts and summaries share the exact metadata policy used by the registers.
                    if (! $manager->canRead($request->user(), $record)) {
                        continue;
                    }
                    $totals[$kind]++;
                    $state = $record->operation;
                    $date = $kind === 'incoming' ? $record->received_date : $record->document_date;
                    $item = ['id' => $record->id, 'kind' => $kind, 'subject' => $record->subject, 'number' => $record->internal_number,
                        'date' => $date->format('Y-m-d'), 'created_at' => $record->created_at->toISOString(),
                        'status' => $state?->status ?? ($kind === 'incoming' && $record->status === 'registered' ? 'received' : $record->status),
                        'due_date' => null, 'href' => route('dcms.'.$kind, ['unit' => $context['key'], 'record' => $record->id], false)];
                    $recent[] = $item;
                    usort($recent, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']) ?: $b['id'] <=> $a['id']);
                    $recent = array_slice($recent, 0, 8);
                    // Only incoming operations define a due date in the agreed business workflow.
                    if ($kind === 'incoming' && $state?->due_date && $state->status !== 'closed') {
                        $dueDate = $state->due_date->format('Y-m-d');
                        if ($dueDate <= $soon) {
                            $totals[$dueDate < $today ? 'overdue' : 'due_soon']++;
                            $due[] = array_replace($item, ['due_date' => $dueDate]);
                            usort($due, fn (array $a, array $b): int => strcmp($a['due_date'], $b['due_date']) ?: $a['id'] <=> $b['id']);
                            $due = array_slice($due, 0, 8);
                        }
                    }
                }
            }
        }

        return response()->json(['totals' => $totals, 'recent' => $recent, 'due' => $due, 'quick_actions' => array_values($quickActions)], headers: ['Cache-Control' => 'no-store']);
    }
}
