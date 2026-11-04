<?php

namespace App\Console\Commands;

use App\Authorization\AccessControl;
use App\Authorization\ImportXpplMasterData;
use App\Authorization\RbacMutation;
use App\Authorization\UatImportTarget;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('uat:import-xppl-master-data
    {--apply : Apply the approved insert-only plan; default is read-only}
    {--approve= : Exact plan SHA-256 approved by the Product Owner}
    {--approve-provisional= : Source SHA-256 explicitly approved for provisional UAT use}
    {--approval-reference= : Product Owner approval/ticket reference}
    {--actor= : Existing active global security administrator ID}
    {--backup= : Existing verified private backup artifact}
    {--backup-manifest= : Private JSON backup identity and restore-test attestation}
    {--confirm= : Exact administrator confirmation phrase printed by preview}')]
#[Description('Guarded XPPL UAT master-data import; preview never writes or connects to DEV')]
class ImportUatXpplMasterData extends Command
{
    public function handle(UatImportTarget $guard, ImportXpplMasterData $importer, AccessControl $access, RbacMutation $mutation): int
    {
        try {
            $target = $guard->verify();
            $source = $importer->source();
            $plan = $importer->plan($source['data']);
            $hash = $this->planHash($target, $source['hash'], $plan);
            $phrase = 'IMPORT XPPL '.$target['database'].' ON '.$target['server_uuid'].' PLAN '.$hash;
            $this->line('Target JSON: '.json_encode($target, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->line('Source SHA-256: '.$source['hash']);
            $this->line('Plan SHA-256: '.$hash);
            $this->line('Administrator confirmation: '.$phrase);
            $this->table(['Kind', 'Business code', 'Action', 'Reason'], array_map(fn (array $row): array => [$row['kind'], $row['code'], $row['action'], $row['reason']], $plan));
            foreach (['companies', 'sites', 'scopes', 'departments', 'categories', 'registers', 'types', 'mappings'] as $kind) {
                $counts = array_count_values(array_column(array_filter($plan, fn (array $row): bool => $row['kind'] === $kind), 'action'));
                $this->line($kind.': create='.($counts['create'] ?? 0).', skip='.($counts['skip'] ?? 0).', conflict='.($counts['conflict'] ?? 0));
            }
            $this->line('Provisional issues: '.count($source['issues']));
            if ($source['issues'] !== []) {
                $this->warn('NOT UAT-READY WITHOUT PRODUCT OWNER APPROVAL: provisional labels, codes and mappings below.');
                $this->table(['Source field', 'Issue / value'], $source['issues']);
            }
            $counts = array_count_values(array_column($plan, 'action'));
            if (isset($counts['conflict'])) {
                throw new RuntimeException('Conflicts detected; no records, codes, labels or mappings will be overwritten.');
            }
            if (! $this->option('apply')) {
                $this->info('DRY RUN: created=0; planned='.($counts['create'] ?? 0).'; skipped='.($counts['skip'] ?? 0).'; conflicts=0. No database, audit, backup or permission writes.');

                return self::SUCCESS;
            }
            if (! hash_equals($hash, (string) $this->option('approve')) || trim((string) $this->option('approval-reference')) === '') {
                throw new RuntimeException('Apply requires the exact reviewed plan hash and a Product Owner approval reference.');
            }
            if ($source['issues'] !== [] && ! hash_equals($source['hash'], (string) $this->option('approve-provisional'))) {
                throw new RuntimeException('Unresolved provisional values require explicit Product Owner approval of the exact source hash.');
            }
            $actor = User::query()->whereKey((string) $this->option('actor'))->where('active', true)->first();
            if (! $actor || ! $access->isSystemAdministrator($actor)) {
                throw new RuntimeException('An existing active global security administrator is required; no user or role will be created.');
            }
            $confirmation = $this->option('confirm');
            if ($confirmation === null && $this->input->isInteractive()) {
                $confirmation = $this->ask('Type exactly: '.$phrase);
            }
            if ($confirmation !== $phrase) {
                throw new RuntimeException('Exact administrator target/plan confirmation required; --no-interaction never implies approval.');
            }
            $guard->verifyBackup((string) $this->option('backup'), (string) $this->option('backup-manifest'), $target, $hash, $actor->id);
            $mutation->run(function () use ($guard, $target, $source, $hash, $actor, $access, $importer): void {
                if ($guard->verify() !== $target || ! $access->isSystemAdministrator($actor)) {
                    throw new RuntimeException('Target or administrator authority changed; import refused.');
                }
                $locked = $importer->plan($source['data'], true);
                if (! hash_equals($hash, $this->planHash($target, $source['hash'], $locked))) {
                    throw new RuntimeException('Target master data changed since approval; rerun preview and backup verification.');
                }
                $importer->apply($source['data'], $actor);
                if (array_diff(array_column($importer->plan($source['data']), 'action'), ['skip']) !== []) {
                    throw new RuntimeException('Post-import verification failed; entire import rolled back.');
                }
            });
            $this->info('IMPORTED: created='.($counts['create'] ?? 0).'; skipped='.($counts['skip'] ?? 0).'; conflicts=0. Approval: '.$this->option('approval-reference'));
            if ($source['issues'] !== []) {
                $this->warn('Provisional values retained exactly as approved for UAT; no translations or official codes were invented.');
            }

            return self::SUCCESS;
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());
            $this->error('Import refused or rolled back; no partial master-data import committed.');

            return self::FAILURE;
        }
    }

    /** @param array<string, string> $target
     * @param  list<array<string, mixed>>  $plan
     */
    private function planHash(array $target, string $sourceHash, array $plan): string
    {
        return hash('sha256', json_encode([$target, $sourceHash, $plan], JSON_THROW_ON_ERROR));
    }
}
