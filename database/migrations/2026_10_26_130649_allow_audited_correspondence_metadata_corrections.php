<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['incoming', 'outgoing'] as $kind) {
            DB::statement('DROP TRIGGER '.$this->originalName($kind));
            $identity = ['id', 'scope_id', 'profile_id', 'register_id', 'created_by'];
            $registeredIdentity = ['type_id', 'status', 'internal_number', 'registered_at', 'registered_by', 'created_at'];
            if ($kind === 'outgoing') {
                $registeredIdentity[] = 'official_number';
            }
            $identityChanged = implode(' OR ', array_map(fn (string $field): string => 'NOT ('.$this->same('NEW.'.$field, 'OLD.'.$field).')', $identity));
            $registeredChanged = implode(' OR ', array_map(fn (string $field): string => 'NOT ('.$this->same('NEW.'.$field, 'OLD.'.$field).')', $registeredIdentity));
            $fields = $kind === 'incoming'
                ? ['subject', 'description_en', 'description_lo', 'priority', 'sender_organization', 'sender_contact', 'sender_email', 'sender_phone', 'external_reference', 'correspondence_date', 'received_date', 'channel']
                : ['subject', 'description_en', 'description_lo', 'priority', 'signatory_name', 'signatory_title', 'document_date'];
            $matches = $this->matches($fields, "'$.details.");
            $audit = $this->audit($kind, 'OLD.id').' AND a.actor_id = NEW.updated_by AND '.$this->same('NEW.updated_at', 'a.created_at')." AND {$matches}";
            $condition = "({$identityChanged}) OR (OLD.status = 'registered' AND (({$registeredChanged}) OR NOT EXISTS ({$audit})))";
            if ($kind === 'outgoing') {
                $condition .= " OR (NEW.status = 'registered' AND NOT EXISTS (SELECT 1 FROM outgoing_recipients r WHERE r.correspondence_id = OLD.id AND r.kind = 'primary'))";
            }
            $this->guard($kind.'_correspondences', 'audited_'.$kind.'_details', $condition);
        }
        DB::statement('DROP TRIGGER '.$this->recipientOriginalName());
        $fields = ['organization', 'contact_name', 'email', 'phone', 'address'];
        $path = DB::getDriverName() === 'sqlite'
            ? "'$.details.recipients.\"' || OLD.id || '\"."
            : "CONCAT('$.details.recipients.\"', OLD.id, '\".";
        $matches = $this->matches($fields, $path, true);
        $audit = $this->audit('outgoing', 'OLD.correspondence_id')." AND {$matches} AND ".$this->same('NEW.updated_at', 'a.created_at');
        $identity = implode(' OR ', array_map(fn (string $field): string => 'NOT ('.$this->same('NEW.'.$field, 'OLD.'.$field).')', ['id', 'correspondence_id', 'kind', 'created_at']));
        $this->guard('outgoing_recipients', 'audited_outgoing_recipient_details', "NEW.id <> OLD.id OR NEW.correspondence_id <> OLD.correspondence_id OR (NOT EXISTS (SELECT 1 FROM outgoing_correspondences c WHERE c.id = OLD.correspondence_id AND c.status = 'draft') AND (({$identity}) OR NOT EXISTS ({$audit})))");
    }

    public function down(): void
    {
        foreach (['incoming', 'outgoing'] as $kind) {
            DB::statement('DROP TRIGGER audited_'.$kind.'_details');
            $this->guard($kind.'_correspondences', $this->originalName($kind), $this->originalCondition($kind));
        }
        DB::statement('DROP TRIGGER audited_outgoing_recipient_details');
        $this->guard('outgoing_recipients', $this->recipientOriginalName(), $this->recipientOriginalCondition());
    }

    private function same(string $left, string $right): string
    {
        return DB::getDriverName() === 'sqlite' ? "{$left} IS {$right}" : "CAST({$left} AS BINARY) <=> CAST({$right} AS BINARY)";
    }

    /** @param list<string> $fields */
    private function matches(array $fields, string $pathPrefix, bool $recipient = false): string
    {
        $conditions = [];
        foreach ($fields as $field) {
            $path = $pathPrefix.$field."'".($recipient && DB::getDriverName() !== 'sqlite' ? ')' : '');
            $values = [];
            foreach (['before', 'after'] as $side) {
                $extract = DB::getDriverName() === 'sqlite' ? "json_extract(a.`{$side}`, {$path})" : "CASE WHEN JSON_TYPE(JSON_EXTRACT(a.`{$side}`, {$path})) = 'NULL' THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(a.`{$side}`, {$path})) END";
                $exists = DB::getDriverName() === 'sqlite' ? "json_type(a.`{$side}`, {$path}) IS NOT NULL" : "JSON_CONTAINS_PATH(a.`{$side}`, 'one', {$path}) = 1";
                $values[] = '('.$exists.' AND '.$this->same($extract, ($side === 'before' ? 'OLD.' : 'NEW.').$field).')';
            }
            $conditions[] = '('.$this->same('NEW.'.$field, 'OLD.'.$field).' OR ('.implode(' AND ', $values).'))';
        }

        return implode(' AND ', $conditions);
    }

    private function audit(string $kind, string $id): string
    {
        $table = $kind === 'incoming' ? 'incoming_correspondence_operations' : 'outgoing_operations';
        $action = $kind === 'incoming' ? 'updated' : 'note';
        $condition = "SELECT 1 FROM {$kind}_operation_audits a WHERE a.correspondence_id = {$id} AND a.action = '{$action}' AND LENGTH(TRIM(a.note)) >= 10 AND a.revision = COALESCE((SELECT o.revision FROM {$table} o WHERE o.correspondence_id = {$id}), 0) + 1 AND NOT EXISTS (SELECT 1 FROM {$table} o WHERE o.correspondence_id = {$id} AND o.status = 'closed')";
        if ($kind === 'outgoing') {
            $condition .= " AND NOT EXISTS (SELECT 1 FROM outgoing_operations o WHERE o.correspondence_id = {$id} AND (o.status <> 'registered' OR o.dispatch_date IS NOT NULL)) AND NOT EXISTS (SELECT 1 FROM outgoing_operation_audits h WHERE h.correspondence_id = {$id} AND h.action IN ('dispatch', 'correct_dispatch', 'delivery')) AND NOT EXISTS (SELECT 1 FROM outgoing_deliveries d WHERE d.correspondence_id = {$id})";
        }

        return $condition;
    }

    private function originalCondition(string $kind): string
    {
        return $kind === 'incoming'
            ? "OLD.status = 'registered' OR NEW.scope_id <> OLD.scope_id OR NEW.profile_id <> OLD.profile_id OR NEW.register_id <> OLD.register_id OR NEW.created_by <> OLD.created_by"
            : "OLD.status = 'registered' OR NEW.id <> OLD.id OR NEW.scope_id <> OLD.scope_id OR NEW.profile_id <> OLD.profile_id OR NEW.register_id <> OLD.register_id OR NEW.created_by <> OLD.created_by OR (NEW.status = 'registered' AND NOT EXISTS (SELECT 1 FROM outgoing_recipients r WHERE r.correspondence_id = OLD.id AND r.kind = 'primary'))";
    }

    private function originalName(string $kind): string
    {
        return $kind === 'incoming'
            ? 'incoming_correspondences_UPDATE_'.substr(md5($this->originalCondition($kind)), 0, 6)
            : 'outgoing_'.substr(md5('outgoing_correspondencesUPDATE'.$this->originalCondition($kind)), 0, 24);
    }

    private function recipientOriginalCondition(): string
    {
        return "NOT EXISTS (SELECT 1 FROM outgoing_correspondences c WHERE c.id = NEW.correspondence_id AND c.status = 'draft') OR NEW.id <> OLD.id OR NEW.correspondence_id <> OLD.correspondence_id";
    }

    private function recipientOriginalName(): string
    {
        return 'outgoing_'.substr(md5('outgoing_recipientsUPDATE'.$this->recipientOriginalCondition()), 0, 24);
    }

    private function guard(string $table, string $name, string $condition): void
    {
        DB::statement(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE UPDATE ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Protected correspondence correction'); END"
            : "CREATE TRIGGER {$name} BEFORE UPDATE ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protected correspondence correction'; END IF; END");
    }
};
