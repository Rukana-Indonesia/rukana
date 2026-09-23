<?php

namespace App\Enums;

enum LetterAction: string
{
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Approved = 'approved';
    case Received = 'received';
    case Ready = 'ready';
    case PickedUp = 'picked_up';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Diajukan',
            self::Verified => 'Diverifikasi',
            self::Approved => 'Disetujui',
            self::Received => 'Diterima',
            self::Ready => 'Siap diambil',
            self::PickedUp => 'Diambil',
            self::Rejected => 'Ditolak',
        };
    }
}
