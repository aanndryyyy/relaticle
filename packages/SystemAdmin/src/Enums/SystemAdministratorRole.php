<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Enums;

/**
 * What each role may do, in one place. Every policy in this package answers from
 * these predicates rather than comparing cases, so a new role is decided here once.
 * They are match expressions on purpose: this package is excluded from PHPStan, so
 * an unhandled case has to fail loudly at runtime instead of defaulting to allowed.
 */
enum SystemAdministratorRole: string
{
    case SuperAdministrator = 'super_administrator';
    case Administrator = 'administrator';

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Super Administrator',
            self::Administrator => 'Administrator',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'danger',
            self::Administrator => 'warning',
        };
    }

    /**
     * Covers delete, deleteAny, forceDelete and forceDeleteAny.
     */
    public function canDelete(): bool
    {
        return match ($this) {
            self::SuperAdministrator => true,
            self::Administrator => false,
        };
    }

    /**
     * Reading, creating and editing administrator accounts, and the API tokens
     * that hang off one.
     */
    public function canAdministerStaff(): bool
    {
        return match ($this) {
            self::SuperAdministrator => true,
            self::Administrator => false,
        };
    }
}
