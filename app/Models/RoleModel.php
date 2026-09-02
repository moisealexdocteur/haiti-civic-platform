<?php

namespace App\Models;

final class RoleModel extends TenantScopedModel
{
    protected string $table = 'roles';
    protected string $primaryKey = 'id';
}
