<?php

namespace App\Enums;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu verifikasi',
            self::Active => 'Aktif',
            self::Rejected => 'Ditolak',
            self::Suspended => 'Dinonaktifkan',
        };
    }
}
