<?php

namespace App\Enums;

enum ResidencyStatus: string
{
    case Tetap = 'tetap';
    case Kontrak = 'kontrak';
    case Kos = 'kos';

    public function label(): string
    {
        return match ($this) {
            self::Tetap => 'Tetap',
            self::Kontrak => 'Kontrak',
            self::Kos => 'Kos',
        };
    }
}
