<?php

namespace App\Enums;

enum LetterFlow: string
{
    case Rt = 'rt';
    case RtRw = 'rt_rw';
    case RtRwVillage = 'rt_rw_village';

    public function label(): string
    {
        return match ($this) {
            self::Rt => 'Sampai RT',
            self::RtRw => 'RT → RW',
            self::RtRwVillage => 'RT → RW → Desa',
        };
    }
}
