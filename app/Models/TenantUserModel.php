<?php

namespace App\Models;

final class TenantUserModel extends TenantScopedModel
{
    protected string $table = 'tenant_users';
    protected string $primaryKey = 'id';
}
