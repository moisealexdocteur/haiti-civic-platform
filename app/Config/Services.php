<?php

namespace Config;

use App\Services\AuthorizationService;
use App\Services\TenantContext;
use CodeIgniter\Config\BaseService;

class Services extends BaseService
{
    public static function tenantContext(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('tenantContext');
        }

        return new TenantContext();
    }

    public static function authorization(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('authorization');
        }

        return new AuthorizationService(
            static::tenantContext()
        );
    }
}
