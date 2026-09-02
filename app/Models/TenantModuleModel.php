<?php

namespace App\Models;

final class TenantModuleModel extends TenantScopedModel
{
    protected string $table = 'tenant_modules';
    protected string $primaryKey = 'module_id';
}
