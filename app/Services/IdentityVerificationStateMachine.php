<?php

namespace App\Services;

use InvalidArgumentException;

final class IdentityVerificationStateMachine
{
    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    /**
     * Contrat minimal Sprint 3.
     *
     * verified est volontairement terminal tant qu'une
     * politique explicite de révocation/réexamen n'existe pas.
     */
    private const TRANSITIONS = [
        self::PENDING => [
            self::VERIFIED,
            self::REJECTED,
        ],

        self::REJECTED => [
            self::PENDING,
        ],

        self::VERIFIED => [],
    ];

    public function statuses(): array
    {
        return [
            self::PENDING,
            self::VERIFIED,
            self::REJECTED,
        ];
    }

    public function isValidStatus(
        string $status
    ): bool {
        return array_key_exists(
            $status,
            self::TRANSITIONS
        );
    }

    public function assertStatus(
        string $status
    ): void {
        if (! $this->isValidStatus($status)) {
            throw new InvalidArgumentException(
                'Unknown identity verification status.'
            );
        }
    }

    public function canTransition(
        string $fromStatus,
        string $toStatus
    ): bool {
        $this->assertStatus($fromStatus);
        $this->assertStatus($toStatus);

        return in_array(
            $toStatus,
            self::TRANSITIONS[$fromStatus],
            true
        );
    }

    public function assertTransition(
        string $fromStatus,
        string $toStatus
    ): void {
        if (
            ! $this->canTransition(
                $fromStatus,
                $toStatus
            )
        ) {
            throw new InvalidArgumentException(
                'Identity verification transition is not allowed.'
            );
        }
    }

    public function requiresReason(
        string $fromStatus,
        string $toStatus
    ): bool {
        $this->assertStatus($fromStatus);
        $this->assertStatus($toStatus);

        return $toStatus === self::REJECTED;
    }
}
