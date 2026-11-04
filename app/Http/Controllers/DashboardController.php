<?php

namespace App\Http\Controllers;

use App\Authorization\DocumentReporting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DocumentReporting $reports): JsonResponse
    {
        $filters = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'site_id' => ['required', 'integer', 'min:1'], 'department_id' => ['nullable', 'integer', 'min:1']]);
        $filters['all_departments'] = ! $request->filled('department_id');
        $contexts = $reports->contexts($request->user(), $filters);
        $documents = $reports->documents($request->user(), [], $contexts);
        $totals = $reports->totals(clone $documents);
        $recent = (clone $documents)->whereIn('register', ['incoming', 'outgoing'])->orderByDesc('created_at')->orderBy('register')->orderByDesc('id')->limit(8)->get();
        $due = (clone $documents)->where('register', 'incoming')->where('registered', 1)->where('status', '<>', 'closed')
            ->whereDate('due_date', '<=', today()->addDays(7))->orderBy('due_date')->orderBy('id')->limit(8)->get();
        $reviews = (clone $documents)->whereNotNull('authoritative_revision')->whereDate('review_date', '<=', today()->addDays(30))->orderBy('review_date')->orderBy('id')->limit(8)->get();
        $quickActions = [];
        foreach ($contexts as $context) {
            foreach (['incoming', 'outgoing'] as $kind) {
                if (in_array($kind.'.drafts.manage', $context['permissions'], true)) {
                    $quickActions[$kind] = ['kind' => $kind, 'href' => route('dcms.'.$kind, ['unit' => $context['key'], 'create' => 1], false)];
                }
            }
        }
        $links = [];
        if (array_filter($contexts, fn (array $context): bool => in_array('reports.view', $context['permissions'], true))) {
            $base = ['unit' => $contexts[0]['key'], 'all_departments' => $filters['all_departments'] ? 1 : 0];
            foreach (['incoming' => ['register' => 'incoming'], 'outgoing' => ['register' => 'outgoing'], 'controlled' => ['register' => 'controlled'], 'open_incoming' => ['register' => 'incoming', 'metric' => 'open'], 'open_outgoing' => ['register' => 'outgoing', 'metric' => 'open'], 'closed' => ['metric' => 'closed'], 'pending_response' => ['metric' => 'pending_response'], 'due_soon' => ['metric' => 'due_soon'], 'overdue' => ['metric' => 'overdue'], 'review_soon' => ['register' => 'controlled', 'metric' => 'review_soon'], 'review_overdue' => ['register' => 'controlled', 'metric' => 'review_overdue'], 'checkout_overdue' => ['view' => 'physical', 'metric' => 'checkout_overdue']] as $key => $parameters) {
                $links[$key] = route('dcms.reports', $parameters + $base, false);
            }
        }
        $format = function (object $row) use ($reports): array {
            $value = $reports->row($row);

            return $value + ['kind' => $row->register, 'subject' => $row->title_en, 'date' => $value['document_date']];
        };
        $profileIds = $recent->concat($due)->concat($reviews)->pluck('profile_id')->unique()->values()->all();
        $reports->audit($request->user(), $contexts, $profileIds, 'dashboard.viewed');

        return response()->json(['totals' => $totals, 'recent' => $recent->map($format), 'due' => $due->map($format), 'reviews' => $reviews->map($format),
            'links' => $links, 'trends' => $reports->trends(clone $documents, ['monthly', 'category_en', 'department_en']), 'quick_actions' => array_values($quickActions)], headers: ['Cache-Control' => 'private, no-store']);
    }
}
