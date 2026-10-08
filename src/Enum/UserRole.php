<?php

namespace App\Enum;

enum UserRole: string
{
    case Admin = 'ROLE_ADMIN';
    case Editor = 'ROLE_EDITOR';
    case Author = 'ROLE_AUTHOR';

    public function label(): string
    {
        return 'role.'.strtolower($this->name);
    }
}
