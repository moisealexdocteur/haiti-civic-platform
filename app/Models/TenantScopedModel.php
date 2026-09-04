<?php

namespace App\Models;

use App\Services\TenantContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

abstract class TenantScopedModel
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected ?string $deletedField = null;

    protected TenantContext $tenantContext;
    protected BaseConnection $db;

    public function __construct(
        ?TenantContext $tenantContext = null,
        ?BaseConnection $db = null
    ) {
        $this->tenantContext = $tenantContext
            ?? service('tenantContext');

        $this->db = $db ?? Database::connect();
    }

    public function find(int|string $id): ?array
    {
        if ($id === 0 || $id === '0' || $id === '') {
            throw new InvalidArgumentException(
                'Record ID must be a non-empty identifier.'
            );
        }

        return $this->scopedBuilder()
            ->where(
                $this->table . '.' . $this->primaryKey,
                $id
            )
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    public function findAll(
        ?int $limit = null,
        int $offset = 0
    ): array {
        if ($limit !== null && $limit < 0) {
            throw new InvalidArgumentException(
                'Limit cannot be negative.'
            );
        }

        if ($offset < 0) {
            throw new InvalidArgumentException(
                'Offset cannot be negative.'
            );
        }

        if ($limit === null && $offset !== 0) {
            throw new InvalidArgumentException(
                'Offset requires an explicit limit.'
            );
        }

        $builder = $this->scopedBuilder();

        if ($limit !== null) {
            $builder->limit($limit, $offset);
        }

        return $builder
            ->get()
            ->getResultArray();
    }

    public function first(): ?array
    {
        return $this->scopedBuilder()
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    public function countAllResults(): int
    {
        return (int) $this->scopedBuilder()
            ->countAllResults();
    }

    final protected function scopedBuilder(): BaseBuilder
    {
        $builder = $this->db->table($this->table);

        $builder->where(
            $this->table . '.tenant_id',
            $this->tenantContext->id()
        );

        if ($this->deletedField !== null) {
            $builder->where(
                $this->table . '.' . $this->deletedField,
                null
            );
        }

        return $builder;
    }
}
