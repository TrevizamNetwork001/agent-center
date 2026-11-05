<?php

namespace App\Enums;

enum Role: string
{
    case PlatformAdmin = 'platform_admin';
    case TenantAdmin = 'tenant_admin';
    case Operator = 'operator';
    case Viewer = 'viewer';
}
