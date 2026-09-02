<?php

namespace Config;

use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\OrganizationWriteService;
use App\Services\RoleWriteService;
use App\Services\TenantContext;
use App\Services\TenantModuleWriteService;
use App\Services\TenantUserWriteService;
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

    public static function audit(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('audit');
        }

        return new AuditService(
            static::tenantContext()
        );
    }

    public static function organizationWrites(
        bool $getShared = true
    ) {
        if ($getShared) {
            return static::getSharedInstance(
                'organizationWrites'
            );
        }

        return new OrganizationWriteService(
            static::tenantContext()
        );
    }

    public static function tenantUserWrites(
        bool $getShared = true
    ) {
        if ($getShared) {
            return static::getSharedInstance(
                'tenantUserWrites'
            );
        }

        return new TenantUserWriteService(
            static::tenantContext()
        );
    }


    public static function roleWrites(
        bool $getShared = true
    ) {
        if ($getShared) {
            return static::getSharedInstance(
                'roleWrites'
            );
        }

        return new RoleWriteService(
            static::tenantContext()
        );
    }


    public static function tenantModuleWrites(
        bool $getShared = true
    ) {
        if ($getShared) {
            return static::getSharedInstance(
                'tenantModuleWrites'
            );
        }

        return new TenantModuleWriteService(
            static::tenantContext()
        );
    }

}
