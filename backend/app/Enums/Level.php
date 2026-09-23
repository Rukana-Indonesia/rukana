<?php

namespace App\Enums;

enum Level: string
{
    case Rt = 'rt';
    case Rw = 'rw';
    case Village = 'village';

    public function label(): string
    {
        return match ($this) {
            self::Rt => 'RT',
            self::Rw => 'RW',
            self::Village => 'Desa',
        };
    }
}
