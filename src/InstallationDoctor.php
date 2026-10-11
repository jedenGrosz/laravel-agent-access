<?php

declare(strict_types=1);

namespace LaravelAgentAccess;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

final readonly class InstallationDoctor
{
    public function __construct(private ConfigurationValidator $configuration) {}

    /** Local administrative diagnostics; never serialize connection settings or exception messages.
     * @return array{healthy: bool, checks: list<array{check: string, passed: bool, reason_code: string}>} */
    public function check(): array
    {
        $checks = [];
        try {
            $this->configuration->validate();
            $checks[] = ['check' => 'configuration', 'passed' => true, 'reason_code' => 'VALID'];
        } catch (\Throwable) {
            $checks[] = ['check' => 'configuration', 'passed' => false, 'reason_code' => 'INVALID_CONFIGURATION'];
        }
        $model = config('agent-access.principal_model') ?? config('auth.providers.users.model');
        $validModel = is_string($model) && is_subclass_of($model, Model::class) && is_subclass_of($model, Authenticatable::class);
        $checks[] = ['check' => 'principal_model', 'passed' => $validModel, 'reason_code' => $validModel ? 'VALID' : 'AUTHENTICATABLE_MODEL_REQUIRED'];
        $tables = [
            'agent_access_requests' => ['id', 'principal_type', 'principal_id', 'rule_fingerprint', 'payload_hash'],
            'agent_access_approvals' => ['id', 'request_id', 'actor_id'],
            'agent_access_grants' => ['id', 'principal_id', 'uses', 'expires_at', 'revoked_at'],
            'agent_access_grant_uses' => ['id', 'grant_id', 'execution_id'],
            'agent_access_audit_events' => ['id', 'actor_id', 'execution_id', 'correlation_id', 'metadata'],
        ];
        foreach ($tables as $table => $columns) {
            try {
                $passed = Schema::hasTable($table) && Schema::hasColumns($table, $columns);
                $checks[] = ['check' => $table, 'passed' => $passed, 'reason_code' => $passed ? 'INSTALLED' : 'MIGRATION_REQUIRED'];
            } catch (\Throwable) {
                $checks[] = ['check' => $table, 'passed' => false, 'reason_code' => 'DATABASE_UNAVAILABLE'];
            }
        }
        foreach ([['agent_access_requests', 'laa_request_rule_visibility'], ['agent_access_grants', 'laa_grant_rule_visibility'],
            ['agent_access_grants', 'laa_grant_resource'], ['agent_access_audit_events', 'laa_audit_actor_correlation']] as [$table, $index]) {
            try {
                $passed = Schema::hasTable($table) && Schema::hasIndex($table, $index);
                $checks[] = ['check' => $index, 'passed' => $passed, 'reason_code' => $passed ? 'INSTALLED' : 'MIGRATION_REQUIRED'];
            } catch (\Throwable) {
                $checks[] = ['check' => $index, 'passed' => false, 'reason_code' => 'DATABASE_UNAVAILABLE'];
            }
        }
        if (config('agent-access.http.enabled') && in_array('auth:sanctum', config('agent-access.http.middleware', []), true)) {
            $installed = class_exists(Sanctum::class);
            $checks[] = ['check' => 'http_sanctum', 'passed' => $installed, 'reason_code' => $installed ? 'INSTALLED' : 'AUTHENTICATION_PACKAGE_REQUIRED'];
        }
        if (config('agent-access.notifications.enabled')) {
            $principal = null;
            $notificationSchema = null;
            $installed = false;
            try {
                $principal = is_string($model) && is_subclass_of($model, Model::class) ? new $model : null;
                $notificationSchema = Schema::connection($principal?->getConnectionName());
                $installed = $notificationSchema->hasTable('notifications') && $notificationSchema->hasColumns('notifications', ['id', 'notifiable_type', 'notifiable_id', 'data']);
                $checks[] = ['check' => 'notification_storage', 'passed' => $installed, 'reason_code' => $installed ? 'INSTALLED' : 'HOST_MIGRATION_REQUIRED'];
            } catch (\Throwable) {
                $checks[] = ['check' => 'notification_storage', 'passed' => false, 'reason_code' => 'DATABASE_UNAVAILABLE'];
            }
            if ($installed && $validModel && $principal !== null) {
                try {
                    $columnType = strtolower($notificationSchema->getColumnType('notifications', 'notifiable_id'));
                    $integer = in_array($columnType, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'int2', 'int4', 'int8'], true);
                    $text = in_array($columnType, ['char', 'varchar', 'nvarchar', 'nchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'bpchar', 'character', 'character varying', 'citext', 'clob'], true);
                    $compatible = $principal->getKeyType() === 'string' ? ($text || $columnType === 'uuid') : ($integer || $text);
                    $checks[] = ['check' => 'notification_identity', 'passed' => $compatible, 'reason_code' => $compatible ? 'COMPATIBLE_KEY_TYPE' : 'NOTIFICATION_KEY_TYPE_MISMATCH'];
                } catch (\Throwable) {
                    $checks[] = ['check' => 'notification_identity', 'passed' => false, 'reason_code' => 'DATABASE_UNAVAILABLE'];
                }
            }
            $connection = config('queue.default');
            $driver = is_string($connection) ? config('queue.connections.'.$connection.'.driver') : null;
            $async = is_string($driver) && ! in_array($driver, ['sync', 'null'], true);
            $checks[] = ['check' => 'notification_queue', 'passed' => $async, 'reason_code' => $async ? 'CONFIGURED' : 'ASYNC_QUEUE_REQUIRED'];
            if ($driver === 'database') {
                try {
                    $schema = Schema::connection(config('queue.connections.'.$connection.'.connection'));
                    $table = (string) config('queue.connections.'.$connection.'.table', 'jobs');
                    $installed = $schema->hasTable($table) && $schema->hasColumns($table, [
                        'id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at',
                    ]);
                    $checks[] = ['check' => 'notification_queue_storage', 'passed' => $installed, 'reason_code' => $installed ? 'INSTALLED' : 'HOST_MIGRATION_REQUIRED'];
                } catch (\Throwable) {
                    $checks[] = ['check' => 'notification_queue_storage', 'passed' => false, 'reason_code' => 'DATABASE_UNAVAILABLE'];
                }
            }
        }

        return ['healthy' => ! in_array(false, array_column($checks, 'passed'), true), 'checks' => $checks];
    }
}
