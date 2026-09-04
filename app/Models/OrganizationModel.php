<?php

namespace App\Models;

final class OrganizationModel extends TenantScopedModel
{
    protected string $table = 'organizations';
    protected string $primaryKey = 'id';
    protected ?string $deletedField = 'deleted_at';
}
