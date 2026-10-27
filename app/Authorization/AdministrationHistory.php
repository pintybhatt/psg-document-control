<?php

namespace App\Authorization;

use App\Models\AuthorizationScope;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AdministrationHistory
{
    public const SOURCES = ['master-data' => 'master-data.view', 'numbering' => 'numbering.view', 'roles' => 'roles.view', 'assignments' => 'assignments.view', 'memberships' => 'memberships.view', 'organization' => 'security.audit.view'];

    public function __construct(private AccessControl $access) {}

    /** @return list<string> */
    public function sources(User $actor, AuthorizationScope $scope): array
    {
        return array_keys(array_filter(self::SOURCES, fn (string $permission, string $source): bool => $source === 'organization'
            ? $scope->scope_type === 'global' && $this->access->isSystemAdministrator($actor)
            : ($scope->scope_type !== 'global' || in_array($source, ['roles', 'assignments'], true)) && $this->access->allows($actor, $permission, $scope), ARRAY_FILTER_USE_BOTH));
    }

    public function query(User $actor, AuthorizationScope $scope, string $source): Builder
    {
        $scope = AuthorizationScope::context($scope->scope_type, $scope->company_id, $scope->site_id);
        $this->access->authorize($actor, 'security.audit.view', $scope);
        abort_unless(in_array($source, $this->sources($actor, $scope), true), 403, 'rbacForbidden');

        $query = match ($source) {
            'master-data' => DB::table('master_data_audits as e')->where('e.scope_id', $scope->id)
                ->select(['e.id', 'e.created_at', 'e.actor_id', 'e.subject_type', 'e.subject_id', 'e.action', 'e.before', 'e.after']),
            'organization' => DB::table('rbac_audits as e')->where('e.scope_id', $scope->id)->whereIn('e.subject_type', ['company', 'site', 'department'])
                ->select(['e.id', 'e.created_at', 'e.actor_id', 'e.subject_type', 'e.subject_id', 'e.action', 'e.before', 'e.after']),
            'roles', 'assignments' => DB::table('rbac_audits as e')->where('e.scope_id', $scope->id)->where('e.subject_type', $source === 'roles' ? 'role' : 'assignment')
                ->select(['e.id', 'e.created_at', 'e.actor_id', 'e.subject_type', 'e.subject_id', 'e.action', 'e.before', 'e.after']),
            'memberships' => DB::table('membership_audits as e')->join('organization_memberships as m', 'm.id', '=', 'e.membership_id')
                ->where('m.company_id', $scope->company_id)->where('m.site_id', $scope->site_id)
                ->when($scope->scope_type === 'global', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
                ->select(['e.id', 'e.created_at', 'e.actor_id', 'e.membership_id as subject_id', 'e.action', 'e.before', 'e.after'])
                ->selectRaw("'membership' as subject_type"),
            'numbering' => DB::table('numbering_configurations as e')->join('numbering_series as s', 's.id', '=', 'e.series_id')->where('s.scope_id', $scope->id)
                ->select(['e.id', 'e.created_at', 'e.created_by as actor_id', 'e.series_id as subject_id', 'e.version', 'e.prefix', 'e.padding', 'e.active', 'e.change_reason'])
                ->selectRaw("'numbering' as subject_type, 'configured' as action"),
        };

        return $query->leftJoin('users as actor', 'actor.id', '=', $source === 'numbering' ? 'e.created_by' : 'e.actor_id')
            ->addSelect('actor.name as actor')->orderByDesc('e.created_at')->orderByDesc('e.id');
    }
}
