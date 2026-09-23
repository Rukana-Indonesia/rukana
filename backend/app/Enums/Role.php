<?php

namespace App\Enums;

enum Role: string
{
    case Warga = 'warga';
    case Rt = 'rt';
    case Rw = 'rw';
    case Operator = 'operator';
    case KepalaDesa = 'kepala_desa';

    public function label(): string
    {
        return match ($this) {
            self::Warga => 'Warga',
            self::Rt => 'Pengurus RT',
            self::Rw => 'Pengurus RW',
            self::Operator => 'Operator Desa',
            self::KepalaDesa => 'Kepala Desa',
        };
    }

    /** Pengurus wilayah atau perangkat desa (bukan warga). */
    public function isStaff(): bool
    {
        return $this !== self::Warga;
    }

    /** Scope datanya seluruh desa. */
    public function isVillageWide(): bool
    {
        return in_array($this, [self::Operator, self::KepalaDesa], true);
    }
}
