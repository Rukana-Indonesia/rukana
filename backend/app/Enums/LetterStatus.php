<?php

namespace App\Enums;

enum LetterStatus: string
{
    case Submitted = 'submitted';
    case RtVerified = 'rt_verified';
    case RwApproved = 'rw_approved';
    case VillageReceived = 'village_received';
    case ReadyForPickup = 'ready_for_pickup';
    case Completed = 'completed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Diajukan',
            self::RtVerified => 'Diverifikasi RT',
            self::RwApproved => 'Disetujui RW',
            self::VillageReceived => 'Diterima Desa',
            self::ReadyForPickup => 'Siap Diambil',
            self::Completed => 'Selesai',
            self::Rejected => 'Ditolak',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected], true);
    }
}
