<?php

namespace App\Http\Controllers;

use App\Authorization\ManageApprovalWorkflows;
use App\Http\Requests\ApprovalWorkflowRequest;
use App\Models\ApprovalWorkflowConfiguration;
use Illuminate\Http\JsonResponse;

class ApprovalWorkflowController extends Controller
{
    public function index(ApprovalWorkflowRequest $request, ManageApprovalWorkflows $manager): JsonResponse
    {
        $scope = $request->scopeContext();
        $manager->authorize($request->user(), $scope);
        $current = collect(ManageApprovalWorkflows::REGISTERS)->mapWithKeys(fn (string $register): array => [
            $register => ApprovalWorkflowConfiguration::query()->where('scope_id', $scope->id)->where('register_key', $register)->latest('version')->first(),
        ])->filter();

        return response()->json(['data' => array_map(fn (string $register): array => $current->has($register)
            ? $current[$register]->toArray() : ['id' => null, 'register_key' => $register, 'version' => 0, 'enabled' => false, 'sequence' => 'sequential', 'levels' => []],
            ManageApprovalWorkflows::REGISTERS), 'enforcement_enabled' => true, 'incoming_checkpoint_confirmed' => false])->header('Cache-Control', 'private, no-store');
    }

    public function roles(ApprovalWorkflowRequest $request, ManageApprovalWorkflows $manager): JsonResponse
    {
        return response()->json(['data' => $manager->roles($request->user(), $request->scopeContext())])->header('Cache-Control', 'private, no-store');
    }

    public function store(ApprovalWorkflowRequest $request, string $register, ManageApprovalWorkflows $manager): JsonResponse
    {
        $record = $manager->save($request->user(), $request->scopeContext(), $register, $request->workflowData());

        return response()->json(['data' => $record], 201)->header('Cache-Control', 'private, no-store');
    }

    public function history(ApprovalWorkflowRequest $request, string $register, ManageApprovalWorkflows $manager): JsonResponse
    {
        $scope = $request->scopeContext();
        $manager->authorize($request->user(), $scope);
        abort_unless(in_array($register, ManageApprovalWorkflows::REGISTERS, true), 404);

        return response()->json(ApprovalWorkflowConfiguration::query()->where('scope_id', $scope->id)->where('register_key', $register)
            ->orderByDesc('version')->paginate($request->integer('per_page', 10)))->header('Cache-Control', 'private, no-store');
    }
}
