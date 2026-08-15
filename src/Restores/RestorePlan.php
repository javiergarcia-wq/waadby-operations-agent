<?php

namespace Waadby\OperationsAgent\Restores;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

final class RestorePlan
{
    public const VERSION = 1;

    /** @param array<string, mixed> $preflight @param array<string, mixed> $source @return array<string, mixed> */
    public static function create(array $preflight, array $source, int $ttlSeconds = 900): array
    {
        $type = (string) ($source['type'] ?? '');
        if (! in_array($type, ['local_artifact', 'remote_artifact', 'vault_replica', 'portable_zip'], true)) {
            throw new RuntimeException('El tipo de origen restore no esta permitido.');
        }
        $now = CarbonImmutable::now('UTC');
        $plan = [
            'plan_version' => self::VERSION,
            'plan_id' => (string) Str::uuid(),
            'compatible' => true,
            'verification_source' => (string) ($preflight['verification_source'] ?? 'verified'),
            'data_modified' => false,
            'source' => [
                'type' => $type,
                'backup_id' => (string) ($source['backup_id'] ?? $preflight['backup_id']),
                'artifact_id' => $source['artifact_id'] ?? null,
                'remote_artifact_id' => $source['remote_artifact_id'] ?? null,
                'vault_replica_id' => $source['vault_replica_id'] ?? null,
                'remote_session_id' => $source['remote_session_id'] ?? null,
                'sha256' => strtolower((string) $preflight['backup_sha256']),
                'size_bytes' => (int) $preflight['backup_size_bytes'],
            ],
            'target' => [
                'application_code' => (string) $preflight['application_code'],
                'environment' => (string) $preflight['environment'],
                'current_version' => (string) $preflight['current_version'],
                'backup_version' => (string) $preflight['backup_version'],
                'database_driver' => $preflight['configured_database_driver'],
            ],
            'components' => $preflight['components'],
            'migration_baseline' => $preflight['migration_baseline'],
            'target_migration_state' => $preflight['target_migration_state'],
            'forward_migrations' => array_values($preflight['forward_migrations']),
            'migration_policy' => 'forward_only',
            'configuration_policy' => 'preserve_local',
            'safety' => [
                'safety_backup_required' => true,
                'vault_required' => (string) $preflight['environment'] === 'production',
                'maintenance_required' => true,
                'integration_delivery_hold_required' => true,
                'code_snapshot_applied' => false,
            ],
            'created_at' => $now->toIso8601String(),
            'expires_at' => $now->addSeconds(max(60, $ttlSeconds))->toIso8601String(),
        ];
        $plan['plan_sha256'] = self::hash($plan);

        return $plan;
    }

    /** @param array<string, mixed> $plan */
    public static function validate(array $plan): void
    {
        if (($plan['plan_version'] ?? null) !== self::VERSION || ! Str::isUuid((string) ($plan['plan_id'] ?? ''))) {
            throw new RuntimeException('El plan restore no tiene una identidad/version valida.');
        }
        $expected = (string) ($plan['plan_sha256'] ?? '');
        if (! preg_match('/^[a-f0-9]{64}$/', $expected) || ! hash_equals($expected, self::hash($plan))) {
            throw new RuntimeException('El plan restore fue alterado o esta corrupto.');
        }
        if (CarbonImmutable::parse((string) $plan['expires_at'])->isPast()) {
            throw new RuntimeException('El plan restore ha expirado; debe repetirse el preflight.');
        }
        if (($plan['configuration_policy'] ?? null) !== 'preserve_local' || ($plan['safety']['code_snapshot_applied'] ?? true) !== false) {
            throw new RuntimeException('El plan solicita una politica de configuracion/codigo no permitida.');
        }
        foreach (['count', 'last', 'names_sha256', 'available_names_sha256', 'available_count'] as $key) {
            if (! array_key_exists($key, (array) ($plan['target_migration_state'] ?? []))) {
                throw new RuntimeException('El plan restore no fija completamente el destino de migrations.');
            }
        }
        if (! is_array($plan['forward_migrations'] ?? null)) {
            throw new RuntimeException('El plan restore no contiene la lista forward exacta.');
        }
    }

    /** @param array<string, mixed> $plan */
    public static function hash(array $plan): string
    {
        unset($plan['plan_sha256']);

        return hash('sha256', json_encode(self::canonicalize($plan), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
