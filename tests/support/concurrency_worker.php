<?php

use App\Services\OrganizationWriteService;
use App\Services\TenantContext;
use App\Services\TenantUserWriteService;

if ($argc !== 7) {
    fwrite(
        STDERR,
        "Usage: concurrency_worker.php "
        . "<operation> <tenant_id> <actor_user_id> "
        . "<target> <ready_file> <result_file>\n"
    );

    exit(64);
}

[
    $script,
    $operation,
    $tenantIdRaw,
    $actorUserIdRaw,
    $target,
    $readyFile,
    $resultFile,
] = $argv;

unset($script);

$tenantId = filter_var(
    $tenantIdRaw,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

$actorUserId = filter_var(
    $actorUserIdRaw,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($tenantId === false || $actorUserId === false) {
    fwrite(STDERR, "Invalid worker IDs.\n");
    exit(65);
}

define(
    'HOMEPATH',
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
);

define(
    'CONFIGPATH',
    HOMEPATH . 'app/Config/'
);

define(
    'PUBLICPATH',
    HOMEPATH . 'public/'
);

require HOMEPATH
    . 'vendor/codeigniter4/framework/system/Test/bootstrap.php';

$db = db_connect('tests');

$connectionRow = $db
    ->query(
        'SELECT CONNECTION_ID() AS id'
    )
    ->getFirstRow('array');

$connectionId = (int) $connectionRow['id'];

$lockName =
    'civic_audit_tenant_' . $tenantId;

$holderRow = $db
    ->query(
        'SELECT IS_USED_LOCK(?) AS holder',
        [$lockName]
    )
    ->getFirstRow('array');

$observedHolder =
    $holderRow['holder'] === null
        ? null
        : (int) $holderRow['holder'];

if ($observedHolder === null) {
    fwrite(
        STDERR,
        "Parent audit lock is not held.\n"
    );

    exit(66);
}

/*
 * Le chronomètre démarre AVANT le signal ready.
 * Le parent ne libérera le verrou qu'après que
 * les deux workers auront signalé leur disponibilité.
 */
$startedAt = microtime(true);

$readyPayload = json_encode(
    [
        'pid' => getmypid(),
        'connection_id' => $connectionId,
        'observed_lock_holder' =>
            $observedHolder,
        'started_at' => $startedAt,
    ],
    JSON_THROW_ON_ERROR
);

if (
    file_put_contents(
        $readyFile,
        $readyPayload,
        LOCK_EX
    ) === false
) {
    fwrite(STDERR, "Could not write ready file.\n");
    exit(67);
}

$result = [
    'ok' => false,
    'operation' => $operation,
    'pid' => getmypid(),
    'connection_id' => $connectionId,
    'observed_lock_holder' =>
        $observedHolder,
    'entity_id' => null,
    'exception_class' => null,
    'exception_message' => null,
];

try {
    $context = (new TenantContext())
        ->set($tenantId);

    switch ($operation) {
        case 'organization_create':
            $service =
                new OrganizationWriteService(
                    $context,
                    db: $db
                );

            $organizationId =
                $service->create(
                    $actorUserId,
                    'Concurrent Organization '
                        . $target,
                    $target
                );

            $result['entity_id'] =
                $organizationId;

            $result['ok'] = true;

            break;

        case 'owner_demote':
            $targetUserId = filter_var(
                $target,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1,
                    ],
                ]
            );

            if ($targetUserId === false) {
                throw new InvalidArgumentException(
                    'Invalid target user ID.'
                );
            }

            $service =
                new TenantUserWriteService(
                    $context,
                    $db
                );

            $service->setOwner(
                $actorUserId,
                $targetUserId,
                false
            );

            $result['entity_id'] =
                $targetUserId;

            $result['ok'] = true;

            break;

        default:
            throw new InvalidArgumentException(
                'Unknown concurrency operation.'
            );
    }
} catch (Throwable $exception) {
    $result['exception_class'] =
        get_class($exception);

    $result['exception_message'] =
        $exception->getMessage();
}

$result['elapsed_ms'] =
    round(
        (microtime(true) - $startedAt)
        * 1000,
        3
    );

if (
    file_put_contents(
        $resultFile,
        json_encode(
            $result,
            JSON_THROW_ON_ERROR
        ),
        LOCK_EX
    ) === false
) {
    fwrite(STDERR, "Could not write result file.\n");
    exit(68);
}

exit(0);
