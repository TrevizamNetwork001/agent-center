<?php

namespace App\Enums;

enum Role: string
{
    case PlatformAdmin = 'platform_admin';
    case TenantAdmin = 'tenant_admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Administrador da plataforma',
            self::TenantAdmin => 'Administrador do tenant',
            self::Operator => 'Operador',
            self::Viewer => 'Somente leitura',
        };
    }
}
