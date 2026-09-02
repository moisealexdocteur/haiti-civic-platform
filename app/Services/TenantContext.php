<?php

namespace App\Services;

use InvalidArgumentException;
use LogicException;

final class TenantContext
{
    private ?int $tenantId = null;

    public function set(int $tenantId): self
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException(
                'Tenant ID must be a positive integer.'
            );
        }

        $this->tenantId = $tenantId;

        return $this;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function id(): int
    {
        if ($this->tenantId === null) {
            throw new LogicException(
                'Tenant context has not been resolved.'
            );
        }

        return $this->tenantId;
    }
}
