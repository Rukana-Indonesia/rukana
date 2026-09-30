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

    /** Tingkat yang dilewati surat dengan alur ini, berurutan. */
    public function levels(): array
    {
        return match ($this) {
            self::Rt => [Level::Rt],
            self::RtRw => [Level::Rt, Level::Rw],
            self::RtRwVillage => [Level::Rt, Level::Rw, Level::Village],
        };
    }

    public function includes(Level $level): bool
    {
        return in_array($level, $this->levels(), true);
    }

    /** Urutan status normal (tanpa penolakan) untuk alur ini, dari diajukan sampai selesai. */
    public function statuses(): array
    {
        return match ($this) {
            self::Rt => [LetterStatus::Submitted, LetterStatus::Completed],
            self::RtRw => [LetterStatus::Submitted, LetterStatus::RtVerified, LetterStatus::Completed],
            self::RtRwVillage => [
                LetterStatus::Submitted, LetterStatus::RtVerified, LetterStatus::RwApproved,
                LetterStatus::VillageReceived, LetterStatus::ReadyForPickup, LetterStatus::Completed,
            ],
        };
    }
}
