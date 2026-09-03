<?php

namespace Tests\Database;

use App\Services\AuditService;
use App\Services\CitizenIdentityWriteService;
use App\Services\IdentityCryptoService;
use App\Services\TenantContext;
use App\Services\VerificationDocumentWriteService;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CitizenIdentityWriteServiceTest
    extends CIUnitTestCase
{
    private const NINU = '0000000000';

    private const PHONE =
        '00000000';

    private int $tenantA;
    private int $tenantB;

    private int $actorA;
    private int $actorB;
    private int $unauthorizedA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();

        $this->cleanupFixtures();
        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();

        parent::tearDown();
    }

    public function testCreateEncryptsAndAuditsIdentity(): void
    {
        $identityId = $this->service(
            $this->tenantA
        )->create(
            $this->actorA,
            $this->formattedNinu('-'),
            self::PHONE,
            'test-consent-v1'
        );

        $identity = $this->identity(
            $this->tenantA,
            $identityId
        );

        $this->assertNotNull($identity);

        $this->assertSame(
            'pending',
            $identity['verification_status']
        );

        $this->assertSame(
            'test-consent-v1',
            $identity['consent_version']
        );

        $this->assertNotSame(
            self::NINU,
            $identity['ninu_ciphertext']
        );

        $this->assertNotSame(
            '+509' . self::PHONE,
            $identity['phone_ciphertext']
        );

        $crypto = new IdentityCryptoService(
            (new TenantContext())
                ->set($this->tenantA)
        );

        $this->assertSame(
            self::NINU,
            $crypto->decryptNinu(
                $identity['ninu_ciphertext'],
                $identity['uuid']
            )
        );

        $this->assertSame(
            '+509' . self::PHONE,
            $crypto->decryptPhone(
                $identity['phone_ciphertext'],
                $identity['uuid']
            )
        );

        $this->assertSame(
            $crypto->ninuFingerprint(
                self::NINU
            ),
            $identity['ninu_fingerprint']
        );

        $event = $this->event(
            $this->tenantA,
            $identityId,
            'identity.created'
        );

        $this->assertNotNull($event);
        $this->assertSame(
            'pending',
            $event['to_status']
        );

        $audit = $this->audit(
            $this->tenantA,
            'citizen_identity.created'
        );

        $this->assertNotNull($audit);
        $this->assertSame(
            (string) $identityId,
            $audit['entity_id']
        );

        foreach ([
            $event['context_json'],
            $audit['context_json'],
        ] as $contextJson) {
            $this->assertStringNotContainsString(
                self::NINU,
                $contextJson
            );

            $this->assertStringNotContainsString(
                '+509' . self::PHONE,
                $contextJson
            );

            $this->assertStringNotContainsString(
                $identity['ninu_fingerprint'],
                $contextJson
            );

            $this->assertStringNotContainsString(
                $identity['ninu_ciphertext'],
                $contextJson
            );

            $this->assertStringNotContainsString(
                $identity['phone_ciphertext'],
                $contextJson
            );
        }

        $verification = (
            new AuditService(
                (new TenantContext())
                    ->set($this->tenantA),
                $this->db
            )
        )->verifyCurrentTenantChain();

        $this->assertTrue(
            $verification['valid']
        );
    }

    public function testEquivalentNinuPresentationIsDuplicate(): void
    {
        $service = $this->service(
            $this->tenantA
        );

        $service->create(
            $this->actorA,
            $this->formattedNinu('-'),
            null,
            'test-consent-v1'
        );

        $exception = $this->captureException(
            fn () => $service->create(
                $this->actorA,
                $this->formattedNinu(' '),
                null,
                'test-consent-v1'
            )
        );

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $exception
        );

        $this->assertSame(
            'Citizen identity already exists '
            . 'in the current tenant.',
            $exception->getMessage()
        );

        $this->assertSame(
            1,
            $this->identityCount(
                $this->tenantA
            )
        );

        $this->assertSame(
            1,
            $this->eventCount(
                $this->tenantA
            )
        );

        $this->assertSame(
            1,
            $this->auditCount(
                $this->tenantA
            )
        );
    }

    public function testSameNinuIsolatedAcrossTenants(): void
    {
        $idA = $this->service(
            $this->tenantA
        )->create(
            $this->actorA,
            self::NINU,
            null,
            'test-consent-v1'
        );

        $idB = $this->service(
            $this->tenantB
        )->create(
            $this->actorB,
            self::NINU,
            null,
            'test-consent-v1'
        );

        $rowA = $this->identity(
            $this->tenantA,
            $idA
        );

        $rowB = $this->identity(
            $this->tenantB,
            $idB
        );

        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);

        /*
         * Les HMAC sont dérivés par tenant :
         * même valeur normalisée, empreintes différentes.
         */
        $this->assertNotSame(
            $rowA['ninu_fingerprint'],
            $rowB['ninu_fingerprint']
        );

        $exception = $this->captureException(
            fn () => $this->service(
                $this->tenantB
            )->updatePhone(
                $this->actorB,
                $idA,
                self::PHONE
            )
        );

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $exception
        );

        $this->assertNull(
            $this->identity(
                $this->tenantA,
                $idA
            )['phone_ciphertext']
        );

        $this->assertSame(
            1,
            $this->auditCount(
                $this->tenantB
            )
        );
    }

    public function testUpdatePhoneIsEncryptedAndAudited(): void
    {
        $service = $this->service(
            $this->tenantA
        );

        $identityId = $service->create(
            $this->actorA,
            self::NINU,
            null,
            'test-consent-v1'
        );

        $service->updatePhone(
            $this->actorA,
            $identityId,
            '0000 0000'
        );

        $identity = $this->identity(
            $this->tenantA,
            $identityId
        );

        $this->assertNotNull(
            $identity['phone_ciphertext']
        );

        $crypto = new IdentityCryptoService(
            (new TenantContext())
                ->set($this->tenantA)
        );

        $this->assertSame(
            '+509' . self::PHONE,
            $crypto->decryptPhone(
                $identity['phone_ciphertext'],
                $identity['uuid']
            )
        );

        $event = $this->event(
            $this->tenantA,
            $identityId,
            'identity.phone_changed'
        );

        $audit = $this->audit(
            $this->tenantA,
            'citizen_identity.phone_changed'
        );

        $this->assertNotNull($event);
        $this->assertNotNull($audit);

        foreach ([
            $event['context_json'],
            $audit['context_json'],
        ] as $contextJson) {
            $this->assertStringNotContainsString(
                '+509' . self::PHONE,
                $contextJson
            );

            $this->assertStringNotContainsString(
                $identity['phone_ciphertext'],
                $contextJson
            );
        }
    }

    public function testUnauthorizedActorCannotCreateIdentity(): void
    {
        $exception = $this->captureException(
            fn () => $this->service(
                $this->tenantA
            )->create(
                $this->unauthorizedA,
                self::NINU,
                null,
                'test-consent-v1'
            )
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $exception
        );

        $this->assertSame(
            'Permission denied: identity.manage',
            $exception->getMessage()
        );

        $this->assertSame(
            0,
            $this->identityCount(
                $this->tenantA
            )
        );

        $this->assertSame(
            0,
            $this->eventCount(
                $this->tenantA
            )
        );

        $this->assertSame(
            0,
            $this->auditCount(
                $this->tenantA
            )
        );
    }

    public function testCreateRollsBackWhenAuditFails(): void
    {
        $this->insertBrokenAudit(
            $this->tenantA
        );

        $exception = $this->captureException(
            fn () => $this->service(
                $this->tenantA
            )->create(
                $this->actorA,
                self::NINU,
                self::PHONE,
                'test-consent-v1'
            )
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $exception
        );

        $this->assertSame(
            'Cannot append to an invalid audit chain.',
            $exception->getMessage()
        );

        /*
         * L'identité ET son événement métier
         * doivent être annulés.
         */
        $this->assertSame(
            0,
            $this->identityCount(
                $this->tenantA
            )
        );

        $this->assertSame(
            0,
            $this->eventCount(
                $this->tenantA
            )
        );

        /*
         * Seule l'entrée invalide préexistante reste.
         */
        $this->assertSame(
            1,
            $this->auditCount(
                $this->tenantA
            )
        );
    }

    public function testUpdatePhoneRollsBackWhenAuditFails(): void
    {
        $service = $this->service(
            $this->tenantA
        );

        $identityId = $service->create(
            $this->actorA,
            self::NINU,
            null,
            'test-consent-v1'
        );

        $before = $this->identity(
            $this->tenantA,
            $identityId
        );

        $this->assertNotNull($before);
        $this->assertNull(
            $before['phone_ciphertext']
        );

        /*
         * La création a produit une chaîne valide.
         * Nous ajoutons ensuite volontairement
         * une extrémité invalide.
         */
        $this->insertBrokenAudit(
            $this->tenantA
        );

        $exception = $this->captureException(
            fn () => $service->updatePhone(
                $this->actorA,
                $identityId,
                self::PHONE
            )
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $exception
        );

        $this->assertSame(
            'Cannot append to an invalid audit chain.',
            $exception->getMessage()
        );

        $after = $this->identity(
            $this->tenantA,
            $identityId
        );

        $this->assertNotNull($after);

        /*
         * Le changement du téléphone doit avoir
         * entièrement disparu après rollback.
         */
        $this->assertNull(
            $after['phone_ciphertext']
        );

        /*
         * Seul l'événement identity.created
         * doit subsister.
         */
        $this->assertSame(
            1,
            $this->eventCount(
                $this->tenantA
            )
        );

        $this->assertNull(
            $this->event(
                $this->tenantA,
                $identityId,
                'identity.phone_changed'
            )
        );

        /*
         * Audit restant :
         * 1 création valide + 1 entrée cassée injectée.
         */
        $this->assertSame(
            2,
            $this->auditCount(
                $this->tenantA
            )
        );

        $this->assertNull(
            $this->audit(
                $this->tenantA,
                'citizen_identity.phone_changed'
            )
        );
    }


    public function testPendingCanTransitionToVerified(): void
    {
        $identityId = $this->service(
            $this->tenantA
        )->create(
            $this->actorA,
            self::NINU,
            null,
            'test-consent-v1'
        );

        $this->service(
            $this->tenantA
        )->transitionVerificationStatus(
            $this->actorA,
            $identityId,
            'verified'
        );

        $identity = $this->identity(
            $this->tenantA,
            $identityId
        );

        $this->assertNotNull($identity);

        $this->assertSame(
            'verified',
            $identity['verification_status']
        );

        $this->assertNotNull(
            $identity['verified_at']
        );

        $event = $this->event(
            $this->tenantA,
            $identityId,
            'identity.verification_status_changed'
        );

        $this->assertNotNull($event);

        $this->assertSame(
            'pending',
            $event['from_status']
        );

        $this->assertSame(
            'verified',
            $event['to_status']
        );

        $this->assertNull(
            $event['reason_code']
        );

        $eventContext = json_decode(
            $event['context_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertFalse(
            $eventContext['reason_present']
        );

        $audit = $this->audit(
            $this->tenantA,
            'citizen_identity.verification_status_changed'
        );

        $this->assertNotNull($audit);

        $auditContext = json_decode(
            $audit['context_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            'pending',
            $auditContext['from_status']
        );

        $this->assertSame(
            'verified',
            $auditContext['to_status']
        );

        $this->assertFalse(
            $auditContext['reason_present']
        );
    }

    public function testRejectedTransitionRequiresReason(): void
    {
        $identityId = $this->service(
            $this->tenantA
        )->create(
            $this->actorA,
            self::NINU,
            null,
            'test-consent-v1'
        );

        $exception = $this->captureException(
            fn () =>
                $this->service(
                    $this->tenantA
                )->transitionVerificationStatus(
                    $this->actorA,
                    $identityId,
                    'rejected'
                )
        );

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $exception
        );

        $this->assertSame(
            'Reason code is required for this '
            . 'identity verification transition.',
            $exception->getMessage()
        );

        $blankException = $this->captureException(
            fn () =>
                $this->service(
                    $this->tenantA
                )->transitionVerificationStatus(
                    $this->actorA,
                    $identityId,
                    'rejected',
                    '   '
                )
        );

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $blankException
        );

        $identity = $this->identity(
            $this->tenantA,
            $identityId
        );

        $this->assertNotNull($identity);

        $this->assertSame(
            'pending',
            $identity['verification_status']
        );

        $this->assertNull(
            $identity['verified_at']
        );

        $this->assertNull(
            $this->event(
                $this->tenantA,
                $identityId,
                'identity.verification_status_changed'
            )
        );

        $this->assertNull(
            $this->audit(
                $this->tenantA,
                'citizen_identity.verification_status_changed'
            )
        );
    }

    public function testRejectedIdentityCanReturnToPending(): void
    {
        $identityId = $this->service(
            $this->tenantA
        )->create(
            $this->actorA,
            self::NINU,
            null,
            'test-consent-v1'
        );

        $service = $this->service(
            $this->tenantA
        );

        $service->transitionVerificationStatus(
            $this->actorA,
            $identityId,
            'rejected',
            'document_mismatch'
        );

        $identity = $this->identity(
            $this->tenantA,
            $identityId
        );

        $this->assertSame(
            'rejected',
            $identity['verification_status']
        );

        $this->assertNull(
            $identity['verified_at']
        );

        $rejectedEvent = $this->event(
            $this->tenantA,
            $identityId,
            'identity.verification_status_changed'
        );

        $this->assertNotNull($rejectedEvent);

        $this->assertSame(
            'pending',
            $rejectedEvent['from_status']
        );

        $this->assertSame(
            'rejected',
            $rejectedEvent['to_status']
        );

        $this->assertSame(
            'document_mismatch',
            $rejectedEvent['reason_code']
        );

        $service->transitionVerificationStatus(
            $this->actorA,
            $identityId,
            'pending'
        );

        $identity = $this->identity(
            $this->tenantA,
            $identityId
        );

        $this->assertSame(
            'pending',
            $identity['verification_status']
        );

        $this->assertNull(
            $identity['verified_at']
        );

        $pendingEvent = $this->event(
            $this->tenantA,
            $identityId,
            'identity.verification_status_changed'
        );

        $this->assertSame(
            'rejected',
            $pendingEvent['from_status']
        );

        $this->assertSame(
            'pending',
            $pendingEvent['to_status']
        );

        $this->assertNull(
            $pendingEvent['reason_code']
        );
    }

    public function testVerifiedIdentityIsTerminal(): void
    {
        $identityId = $this->service(
            $this->tenantA
        )->create(
            $this->actorA,
            self::NINU,
            null,
            'test-consent-v1'
        );

        $service = $this->service(
            $this->tenantA
        );

        $service->transitionVerificationStatus(
            $this->actorA,
            $identityId,
            'verified'
        );

        $before = $this->identity(
            $this->tenantA,
            $identityId
        );

        $exception = $this->captureException(
            fn () =>
                $service->transitionVerificationStatus(
                    $this->actorA,
                    $identityId,
                    'pending'
                )
        );

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $exception
        );

        $this->assertSame(
            'Identity verification transition is not allowed.',
            $exception->getMessage()
        );

        $after = $this->identity(
            $this->tenantA,
            $identityId
        );

        $this->assertSame(
            'verified',
            $after['verification_status']
        );

        $this->assertSame(
            $before['verified_at'],
            $after['verified_at']
        );

        $this->assertSame(
            2,
            $this->eventCount(
                $this->tenantA
            )
        );

        $this->assertSame(
            2,
            $this->auditCount(
                $this->tenantA
            )
        );
    }


    public function testVerificationDocumentsAreVersionedAndAudited(): void
    {
        $identityId = $this->service(
            $this->tenantA
        )->create(
            $this->actorA,
            self::NINU,
            null,
            'test-consent-v1'
        );

        $service = $this->documentService(
            $this->tenantA
        );

        $sha1 = hash(
            'sha256',
            'synthetic-cin-front-v1'
        );

        $sha2 = hash(
            'sha256',
            'synthetic-cin-front-v2'
        );

        $document1 = $service->register(
            $this->actorA,
            $identityId,
            VerificationDocumentWriteService::CIN_FRONT,
            'opaque://test/cin-front/v1',
            'image/jpeg',
            1234,
            $sha1,
            '2026-01-01 00:00:00'
        );

        $document2 = $service->register(
            $this->actorA,
            $identityId,
            VerificationDocumentWriteService::CIN_FRONT,
            'opaque://test/cin-front/v2',
            'image/jpeg',
            1400,
            $sha2,
            '2026-01-02 00:00:00'
        );

        $rows = $this->db
            ->table('verification_documents')
            ->whereIn(
                'id',
                [$document1, $document2]
            )
            ->orderBy(
                'revision_no',
                'ASC'
            )
            ->get()
            ->getResultArray();

        $this->assertCount(
            2,
            $rows
        );

        $this->assertSame(
            1,
            (int) $rows[0]['revision_no']
        );

        $this->assertSame(
            2,
            (int) $rows[1]['revision_no']
        );

        $this->assertSame(
            'active',
            $rows[0]['status']
        );

        $this->assertSame(
            'active',
            $rows[1]['status']
        );

        $event = $this->event(
            $this->tenantA,
            $identityId,
            'identity.document_registered'
        );

        $this->assertNotNull(
            $event
        );

        $this->assertNull(
            $event['from_status']
        );

        $this->assertNull(
            $event['to_status']
        );

        $audit = $this->audit(
            $this->tenantA,
            'citizen_identity.document_registered'
        );

        $this->assertNotNull(
            $audit
        );

        $this->assertStringNotContainsString(
            'opaque://test/',
            $audit['context_json']
        );

        $this->assertStringNotContainsString(
            $sha1,
            $audit['context_json']
        );

        $this->assertStringNotContainsString(
            $sha2,
            $audit['context_json']
        );
    }

    public function testVerificationDocumentAccessIsTenantScopedAndAuthorized(): void
    {
        $identityId = $this->service(
            $this->tenantA
        )->create(
            $this->actorA,
            self::NINU,
            null,
            'test-consent-v1'
        );

        $unauthorized = $this->captureException(
            fn () =>
                $this->documentService(
                    $this->tenantA
                )->register(
                    $this->unauthorizedA,
                    $identityId,
                    VerificationDocumentWriteService::PORTRAIT,
                    'opaque://test/portrait'
                )
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $unauthorized
        );

        $crossTenant = $this->captureException(
            fn () =>
                $this->documentService(
                    $this->tenantB
                )->register(
                    $this->actorB,
                    $identityId,
                    VerificationDocumentWriteService::CIN_BACK,
                    'opaque://test/cin-back'
                )
        );

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $crossTenant
        );

        $this->assertSame(
            0,
            $this->documentCount(
                $this->tenantA
            )
        );

        $this->assertSame(
            0,
            $this->documentCount(
                $this->tenantB
            )
        );
    }

    public function testVerificationDocumentRollsBackWhenAuditFails(): void
    {
        $identityId = $this->service(
            $this->tenantA
        )->create(
            $this->actorA,
            self::NINU,
            null,
            'test-consent-v1'
        );

        $this->insertBrokenAudit(
            $this->tenantA
        );

        $exception = $this->captureException(
            fn () =>
                $this->documentService(
                    $this->tenantA
                )->register(
                    $this->actorA,
                    $identityId,
                    VerificationDocumentWriteService::CIN_FRONT,
                    'opaque://test/rollback'
                )
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $exception
        );

        $this->assertSame(
            0,
            $this->documentCount(
                $this->tenantA
            )
        );

        $this->assertNull(
            $this->event(
                $this->tenantA,
                $identityId,
                'identity.document_registered'
            )
        );

        $this->assertNull(
            $this->audit(
                $this->tenantA,
                'citizen_identity.document_registered'
            )
        );
    }

    private function documentService(
        int $tenantId
    ): VerificationDocumentWriteService {
        return new VerificationDocumentWriteService(
            (new TenantContext())
                ->set($tenantId),
            $this->db
        );
    }

    private function documentCount(
        int $tenantId
    ): int {
        return $this->db
            ->table('verification_documents')
            ->where(
                'tenant_id',
                $tenantId
            )
            ->countAllResults();
    }

    private function service(
        int $tenantId
    ): CitizenIdentityWriteService {
        return new CitizenIdentityWriteService(
            (new TenantContext())
                ->set($tenantId),
            $this->db
        );
    }

    private function identity(
        int $tenantId,
        int $identityId
    ): ?array {
        return $this->db
            ->table('citizen_identities')
            ->where(
                'tenant_id',
                $tenantId
            )
            ->where(
                'id',
                $identityId
            )
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    private function identityCount(
        int $tenantId
    ): int {
        return $this->db
            ->table('citizen_identities')
            ->where(
                'tenant_id',
                $tenantId
            )
            ->countAllResults();
    }

    private function event(
        int $tenantId,
        int $identityId,
        string $eventType
    ): ?array {
        return $this->db
            ->table(
                'identity_verification_events'
            )
            ->where(
                'tenant_id',
                $tenantId
            )
            ->where(
                'citizen_identity_id',
                $identityId
            )
            ->where(
                'event_type',
                $eventType
            )
            ->orderBy(
                'id',
                'DESC'
            )
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    private function eventCount(
        int $tenantId
    ): int {
        return $this->db
            ->table(
                'identity_verification_events'
            )
            ->where(
                'tenant_id',
                $tenantId
            )
            ->countAllResults();
    }

    private function audit(
        int $tenantId,
        string $event
    ): ?array {
        return $this->db
            ->table('audit_logs')
            ->where(
                'tenant_id',
                $tenantId
            )
            ->where(
                'event',
                $event
            )
            ->orderBy(
                'id',
                'DESC'
            )
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    private function auditCount(
        int $tenantId
    ): int {
        return $this->db
            ->table('audit_logs')
            ->where(
                'tenant_id',
                $tenantId
            )
            ->countAllResults();
    }

    private function insertBrokenAudit(
        int $tenantId
    ): void {
        $this->db
            ->table('audit_logs')
            ->insert([
                'tenant_id' =>
                    $tenantId,

                'actor_type' =>
                    'system',

                'event' =>
                    'broken.identity.audit.chain',

                'context_json' =>
                    '{}',

                'entry_hash' =>
                    'broken',

                'occurred_at' =>
                    gmdate(
                        'Y-m-d H:i:s'
                    ),
            ]);
    }

    private function formattedNinu(
        string $separator
    ): string {
        return implode(
            $separator,
            str_split(
                self::NINU,
                8
            )
        );
    }

    private function captureException(
        callable $callback
    ): Throwable {
        try {
            $callback();
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail(
            'Expected operation to throw an exception.'
        );
    }

    private function createFixtures(): void
    {
        $this->tenantA =
            $this->insertTenant(
                '__identity_write_a__',
                'Identity Write A'
            );

        $this->tenantB =
            $this->insertTenant(
                '__identity_write_b__',
                'Identity Write B'
            );

        $this->actorA =
            $this->insertUser(
                'identity-write-a@invalid.example',
                'Identity Write Actor A'
            );

        $this->actorB =
            $this->insertUser(
                'identity-write-b@invalid.example',
                'Identity Write Actor B'
            );

        $this->unauthorizedA =
            $this->insertUser(
                'identity-write-noauth@invalid.example',
                'Identity Write Unauthorized'
            );

        $this->insertMembership(
            $this->tenantA,
            $this->actorA
        );

        $this->insertMembership(
            $this->tenantA,
            $this->unauthorizedA
        );

        $this->insertMembership(
            $this->tenantB,
            $this->actorB
        );

        $permission = $this->db
            ->table('permissions')
            ->select('id')
            ->where(
                'code',
                'identity.manage'
            )
            ->get()
            ->getFirstRow('array');

        if ($permission === null) {
            throw new RuntimeException(
                'identity.manage is absent.'
            );
        }

        $this->grantManagerRole(
            $this->tenantA,
            $this->actorA,
            (int) $permission['id']
        );

        $this->grantManagerRole(
            $this->tenantB,
            $this->actorB,
            (int) $permission['id']
        );
    }

    private function insertTenant(
        string $slug,
        string $name
    ): int {
        $this->db
            ->table('tenants')
            ->insert([
                'uuid' =>
                    $this->uuid(),

                'slug' =>
                    $slug,

                'name' =>
                    $name,
            ]);

        return (int)
            $this->db->insertID();
    }

    private function insertUser(
        string $email,
        string $displayName
    ): int {
        $this->db
            ->table('users')
            ->insert([
                'uuid' =>
                    $this->uuid(),

                'email' =>
                    $email,

                'display_name' =>
                    $displayName,

                'status' =>
                    'active',
            ]);

        return (int)
            $this->db->insertID();
    }

    private function insertMembership(
        int $tenantId,
        int $userId
    ): void {
        $this->db
            ->table('tenant_users')
            ->insert([
                'tenant_id' =>
                    $tenantId,

                'user_id' =>
                    $userId,

                'status' =>
                    'active',

                'is_owner' =>
                    0,
            ]);
    }

    private function grantManagerRole(
        int $tenantId,
        int $userId,
        int $permissionId
    ): void {
        $this->db
            ->table('roles')
            ->insert([
                'uuid' =>
                    $this->uuid(),

                'tenant_id' =>
                    $tenantId,

                'code' =>
                    '__identity_write_manager__',

                'name' =>
                    'Identity Write Manager',
            ]);

        $roleId =
            (int) $this->db->insertID();

        $this->db
            ->table('role_permissions')
            ->insert([
                'role_id' =>
                    $roleId,

                'permission_id' =>
                    $permissionId,
            ]);

        $this->db
            ->table('user_roles')
            ->insert([
                'tenant_id' =>
                    $tenantId,

                'user_id' =>
                    $userId,

                'role_id' =>
                    $roleId,
            ]);
    }

    private function cleanupFixtures(): void
    {
        /*
         * audit_logs est append-only côté runtime.
         * Comme les tests core existants, le nettoyage
         * utilise exclusivement le compte migrateur TEST.
         */
        $cleanupDb =
            $this->privilegedCleanupConnection();

        $cleanupDb->query(
            'TRUNCATE TABLE `audit_logs`'
        );

        $cleanupDb->close();

        $this->db->query(
            "DELETE ive
             FROM identity_verification_events ive
             INNER JOIN tenants t
                 ON t.id = ive.tenant_id
             WHERE t.slug IN (
                '__identity_write_a__',
                '__identity_write_b__'
             )"
        );

        $this->db->query(
            "DELETE vd
             FROM verification_documents vd
             INNER JOIN tenants t
                 ON t.id = vd.tenant_id
             WHERE t.slug IN (
                 '__identity_write_a__',
                 '__identity_write_b__'
             )"
        );

        $this->db->query(
            "DELETE ci
             FROM citizen_identities ci
             INNER JOIN tenants t
                 ON t.id = ci.tenant_id
             WHERE t.slug IN (
                '__identity_write_a__',
                '__identity_write_b__'
             )"
        );

        $this->db->query(
            "DELETE FROM tenants
             WHERE slug IN (
                '__identity_write_a__',
                '__identity_write_b__'
             )"
        );

        $this->db->query(
            "DELETE FROM users
             WHERE email IN (
                'identity-write-a@invalid.example',
                'identity-write-b@invalid.example',
                'identity-write-noauth@invalid.example'
             )"
        );
    }

    private function privilegedCleanupConnection(): \mysqli
    {
        $username =
            getenv(
                'MIGRATION_DB_USERNAME'
            );

        $password =
            getenv(
                'MIGRATION_DB_PASSWORD'
            );

        $database =
            getenv(
                'TEST_DATABASE'
            );

        if (
            ! is_string($username)
            || $username === ''
            || ! is_string($password)
            || $password === ''
            || ! is_string($database)
            || $database === ''
        ) {
            throw new RuntimeException(
                'Privileged test DB credentials are missing.'
            );
        }

        mysqli_report(
            MYSQLI_REPORT_ERROR
            | MYSQLI_REPORT_STRICT
        );

        return new \mysqli(
            'db',
            $username,
            $password,
            $database,
            3306
        );
    }

    private function uuid(): string
    {
        $bytes =
            random_bytes(16);

        $bytes[6] = chr(
            (ord($bytes[6]) & 0x0f)
            | 0x40
        );

        $bytes[8] = chr(
            (ord($bytes[8]) & 0x3f)
            | 0x80
        );

        $hex =
            bin2hex(
                $bytes
            );

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
