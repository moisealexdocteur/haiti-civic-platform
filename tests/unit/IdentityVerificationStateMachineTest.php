<?php

namespace Tests\Unit;

use App\Services\IdentityVerificationStateMachine;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

final class IdentityVerificationStateMachineTest
    extends CIUnitTestCase
{
    private IdentityVerificationStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine =
            new IdentityVerificationStateMachine();
    }

    public function testStatusCatalogIsExplicit(): void
    {
        $this->assertSame(
            [
                'pending',
                'verified',
                'rejected',
            ],
            $this->machine->statuses()
        );
    }

    public function testPendingCanBecomeVerified(): void
    {
        $this->assertTrue(
            $this->machine->canTransition(
                'pending',
                'verified'
            )
        );
    }

    public function testPendingCanBecomeRejected(): void
    {
        $this->assertTrue(
            $this->machine->canTransition(
                'pending',
                'rejected'
            )
        );

        $this->assertTrue(
            $this->machine->requiresReason(
                'pending',
                'rejected'
            )
        );
    }

    public function testRejectedCanReturnToPending(): void
    {
        $this->assertTrue(
            $this->machine->canTransition(
                'rejected',
                'pending'
            )
        );

        $this->assertFalse(
            $this->machine->requiresReason(
                'rejected',
                'pending'
            )
        );
    }

    public function testVerifiedIsTerminalInSprintThree(): void
    {
        $this->assertFalse(
            $this->machine->canTransition(
                'verified',
                'pending'
            )
        );

        $this->assertFalse(
            $this->machine->canTransition(
                'verified',
                'rejected'
            )
        );
    }

    public function testSelfTransitionsAreRejected(): void
    {
        foreach (
            $this->machine->statuses()
            as $status
        ) {
            $this->assertFalse(
                $this->machine->canTransition(
                    $status,
                    $status
                )
            );
        }
    }

    public function testUnknownSourceStatusIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'Unknown identity verification status.'
        );

        $this->machine->canTransition(
            'unknown',
            'pending'
        );
    }

    public function testUnknownTargetStatusIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'Unknown identity verification status.'
        );

        $this->machine->canTransition(
            'pending',
            'unknown'
        );
    }

    public function testAssertTransitionRejectsForbiddenTransition(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'Identity verification transition is not allowed.'
        );

        $this->machine->assertTransition(
            'verified',
            'pending'
        );
    }
}
