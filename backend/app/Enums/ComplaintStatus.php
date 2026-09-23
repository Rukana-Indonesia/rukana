<?php

namespace App\Enums;

enum ComplaintStatus: string
{
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Forwarded = 'forwarded';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Dikirim',
            self::Verified => 'Diverifikasi',
            self::Forwarded => 'Diteruskan',
            self::InProgress => 'Diproses',
            self::Resolved => 'Selesai',
            self::Rejected => 'Ditolak',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Resolved, self::Rejected], true);
    }
}
