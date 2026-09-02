<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TenantModuleWriteService
{
    private const AUDIT_LOCK_TIMEOUT = 5;

    private TenantContext $tenantContext;
    private BaseConnection $db;
    private AuthorizationService $authorization;
    private AuditService $audit;

    public function __construct(
        TenantContext $tenantContext,
        ?BaseConnection $db = null
    ) {
        $this->tenantContext = $tenantContext;
        $this->db = $db ?? Database::connect();

        $this->authorization = new AuthorizationService(
            $tenantContext,
            $this->db
        );

        $this->audit = new AuditService(
            $tenantContext,
            $this->db
        );
    }

    public function setEnabled(
        int $actorUserId,
        string $moduleCode,
        bool $enabled
    ): void {
        $tenantId = $this->tenantContext->id();

        $this->assertPositiveId(
            $actorUserId,
            'Actor user ID'
        );

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            $this->beginTransaction();

            $this->requirePermission(
                $actorUserId,
                'modules.manage'
            );

            $module = $this->moduleByCode(
                $moduleCode
            );

            if (
                ! $enabled
                && (int) $module['is_core'] === 1
            ) {
                throw new RuntimeException(
                    'Core modules cannot be disabled.'
                );
            }

            $current =
                $this->tenantModuleForUpdate(
                    $tenantId,
                    (int) $module['id']
                );

            if (
                $current !== null
                && (bool) $current['enabled'] === $enabled
            ) {
                $this->commitOrFail();
                return;
            }

            $activatedAt = null;

            if ($enabled) {
                if (
                    $current === null
                    || (int) $current['enabled'] === 0
                ) {
                    $activatedAt = gmdate(
                        'Y-m-d H:i:s'
                    );
                } else {
                    $activatedAt =
                        $current['activated_at'];
                }
            } elseif ($current !== null) {
                $activatedAt =
                    $current['activated_at'];
            }

            if ($current === null) {
                $written = $this->db
                    ->table('tenant_modules')
                    ->insert([
                        'tenant_id' =>
                            $tenantId,
                        'module_id' =>
                            (int) $module['id'],
                        'enabled' =>
                            $enabled ? 1 : 0,
                        'activated_at' =>
                            $activatedAt,
                    ]);
            } else {
                $written = $this->db
                    ->table('tenant_modules')
                    ->where(
                        'tenant_id',
                        $tenantId
                    )
                    ->where(
                        'module_id',
                        (int) $module['id']
                    )
                    ->update([
                        'enabled' =>
                            $enabled ? 1 : 0,
                        'activated_at' =>
                            $activatedAt,
                    ]);
            }

            if (! $written) {
                throw new RuntimeException(
                    'Could not update tenant module state.'
                );
            }

            $this->audit->record(
                event: 'tenant_module.enabled_changed',
                actorUserId: $actorUserId,
                entityType: 'tenant_module',
                entityId: (int) $module['id'],
                context: [
                    'module_code' =>
                        (string) $module['code'],
                    'old_enabled' =>
                        $current === null
                            ? null
                            : (bool) $current['enabled'],
                    'new_enabled' =>
                        $enabled,
                ]
            );

            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        } finally {
            $this->releaseAuditLock(
                $lockName
            );
        }
    }

    public function setConfig(
        int $actorUserId,
        string $moduleCode,
        array $config
    ): void {
        $tenantId = $this->tenantContext->id();

        $this->assertPositiveId(
            $actorUserId,
            'Actor user ID'
        );

        $configJson = $this->encodeConfig(
            $config
        );

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            $this->beginTransaction();

            $this->requirePermission(
                $actorUserId,
                'modules.manage'
            );

            $module = $this->moduleByCode(
                $moduleCode
            );

            $current =
                $this->requireTenantModuleForUpdate(
                    $tenantId,
                    (int) $module['id']
                );

            $oldConfigJson =
                $this->normalizeStoredConfig(
                    $current['config_json']
                );

            if ($oldConfigJson === $configJson) {
                $this->commitOrFail();
                return;
            }

            $updated = $this->db
                ->table('tenant_modules')
                ->where(
                    'tenant_id',
                    $tenantId
                )
                ->where(
                    'module_id',
                    (int) $module['id']
                )
                ->update([
                    'config_json' =>
                        $configJson,
                ]);

            if (! $updated) {
                throw new RuntimeException(
                    'Could not update tenant module config.'
                );
            }

            /*
             * Le contenu de configuration n'est pas stocké
             * dans le journal : uniquement ses empreintes.
             */
            $this->audit->record(
                event: 'tenant_module.config_changed',
                actorUserId: $actorUserId,
                entityType: 'tenant_module',
                entityId: (int) $module['id'],
                context: [
                    'module_code' =>
                        (string) $module['code'],
                    'old_config_hash' =>
                        hash(
                            'sha256',
                            $oldConfigJson
                        ),
                    'new_config_hash' =>
                        hash(
                            'sha256',
                            $configJson
                        ),
                ]
            );

            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        } finally {
            $this->releaseAuditLock(
                $lockName
            );
        }
    }

    public function setLicenseWindow(
        int $actorUserId,
        string $moduleCode,
        ?string $licenseStartAt,
        ?string $licenseEndAt
    ): void {
        $tenantId = $this->tenantContext->id();

        $this->assertPositiveId(
            $actorUserId,
            'Actor user ID'
        );

        $licenseStartAt = $this->normalizeDateTime(
            $licenseStartAt,
            'License start'
        );

        $licenseEndAt = $this->normalizeDateTime(
            $licenseEndAt,
            'License end'
        );

        if (
            $licenseStartAt !== null
            && $licenseEndAt !== null
            && $licenseStartAt > $licenseEndAt
        ) {
            throw new InvalidArgumentException(
                'License end must not precede license start.'
            );
        }

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            $this->beginTransaction();

            $this->requirePermission(
                $actorUserId,
                'modules.manage'
            );

            $module = $this->moduleByCode(
                $moduleCode
            );

            $current =
                $this->requireTenantModuleForUpdate(
                    $tenantId,
                    (int) $module['id']
                );

            $oldStart =
                $current['license_start_at'];

            $oldEnd =
                $current['license_end_at'];

            if (
                $oldStart === $licenseStartAt
                && $oldEnd === $licenseEndAt
            ) {
                $this->commitOrFail();
                return;
            }

            $updated = $this->db
                ->table('tenant_modules')
                ->where(
                    'tenant_id',
                    $tenantId
                )
                ->where(
                    'module_id',
                    (int) $module['id']
                )
                ->update([
                    'license_start_at' =>
                        $licenseStartAt,
                    'license_end_at' =>
                        $licenseEndAt,
                ]);

            if (! $updated) {
                throw new RuntimeException(
                    'Could not update tenant module license.'
                );
            }

            $this->audit->record(
                event: 'tenant_module.license_changed',
                actorUserId: $actorUserId,
                entityType: 'tenant_module',
                entityId: (int) $module['id'],
                context: [
                    'module_code' =>
                        (string) $module['code'],
                    'old_license_start_at' =>
                        $oldStart,
                    'old_license_end_at' =>
                        $oldEnd,
                    'new_license_start_at' =>
                        $licenseStartAt,
                    'new_license_end_at' =>
                        $licenseEndAt,
                ]
            );

            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        } finally {
            $this->releaseAuditLock(
                $lockName
            );
        }
    }

    private function moduleByCode(
        string $moduleCode
    ): array {
        $moduleCode = strtolower(
            trim($moduleCode)
        );

        if (
            $moduleCode === ''
            || mb_strlen($moduleCode) > 100
            || ! preg_match(
                '/^[a-z0-9][a-z0-9._-]*$/',
                $moduleCode
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid module code.'
            );
        }

        $row = $this->db
            ->table('modules')
            ->where('code', $moduleCode)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            throw new InvalidArgumentException(
                'Module does not exist in the catalog.'
            );
        }

        return $row;
    }

    private function tenantModuleForUpdate(
        int $tenantId,
        int $moduleId
    ): ?array {
        return $this->db
            ->query(
                <<<'SQL'
SELECT *
FROM `tenant_modules`
WHERE `tenant_id` = ?
  AND `module_id` = ?
LIMIT 1
FOR UPDATE
SQL,
                [
                    $tenantId,
                    $moduleId,
                ]
            )
            ->getFirstRow('array');
    }

    private function requireTenantModuleForUpdate(
        int $tenantId,
        int $moduleId
    ): array {
        $row =
            $this->tenantModuleForUpdate(
                $tenantId,
                $moduleId
            );

        if ($row === null) {
            throw new InvalidArgumentException(
                'Module has not been registered '
                . 'for the current tenant.'
            );
        }

        return $row;
    }

    private function encodeConfig(array $config): string
    {
        /*
         * config_json représente un objet de configuration.
         * Une liste PHP au niveau racine serait ambiguë.
         */
        if (
            $config !== []
            && array_is_list($config)
        ) {
            throw new InvalidArgumentException(
                'Module config must be an associative array.'
            );
        }

        if ($config === []) {
            return '{}';
        }

        return json_encode(
            $this->canonicalize($config),
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );
    }

    private function normalizeStoredConfig(
        mixed $value
    ): string {
        if (
            $value === null
            || $value === ''
        ) {
            return '{}';
        }

        $decoded = json_decode(
            (string) $value,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'Stored module config is not a JSON object.'
            );
        }

        return $this->encodeConfig($decoded);
    }

    private function canonicalize(
        mixed $value
    ): mixed {
        if (! is_array($value)) {
            if (
                is_object($value)
                || is_resource($value)
            ) {
                throw new InvalidArgumentException(
                    'Module config must be JSON serializable.'
                );
            }

            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn ($item) =>
                    $this->canonicalize($item),
                $value
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] =
                $this->canonicalize($item);
        }

        return $value;
    }

    private function normalizeDateTime(
        ?string $value,
        string $field
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $timezone = new DateTimeZone('UTC');

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            $timezone
        );

        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || $date->format('Y-m-d H:i:s') !== $value
            || (
                is_array($errors)
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            )
        ) {
            throw new InvalidArgumentException(
                $field
                . ' must use UTC YYYY-MM-DD HH:MM:SS.'
            );
        }

        return $value;
    }

    private function requirePermission(
        int $actorUserId,
        string $permission
    ): void {
        if (! $this->authorization->userHasPermission(
            $actorUserId,
            $permission
        )) {
            throw new RuntimeException(
                'Actor is not authorized for this operation.'
            );
        }
    }

    private function assertPositiveId(
        int $id,
        string $field
    ): void {
        if ($id <= 0) {
            throw new InvalidArgumentException(
                $field . ' must be positive.'
            );
        }
    }

    private function beginTransaction(): void
    {
        if (! $this->db->transBegin()) {
            throw new RuntimeException(
                'Could not start tenant-module transaction.'
            );
        }
    }

    private function commitOrFail(): void
    {
        if (! $this->db->transStatus()) {
            throw new RuntimeException(
                'Tenant-module transaction failed.'
            );
        }

        if (! $this->db->transCommit()) {
            throw new RuntimeException(
                'Could not commit tenant-module transaction.'
            );
        }
    }

    private function rollbackIfNeeded(): void
    {
        $this->db->transRollback();
    }

    private function auditLockName(
        int $tenantId
    ): string {
        return 'civic_audit_tenant_' . $tenantId;
    }

    private function acquireAuditLock(
        string $lockName
    ): void {
        $row = $this->db
            ->query(
                'SELECT GET_LOCK(?, ?) AS acquired',
                [
                    $lockName,
                    self::AUDIT_LOCK_TIMEOUT,
                ]
            )
            ->getFirstRow('array');

        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException(
                'Could not acquire the audit transaction lock.'
            );
        }
    }

    private function releaseAuditLock(
        string $lockName
    ): void {
        $this->db->query(
            'SELECT RELEASE_LOCK(?)',
            [$lockName]
        );
    }
}
