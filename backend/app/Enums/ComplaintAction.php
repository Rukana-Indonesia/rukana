<?php

namespace App\Enums;

enum ComplaintAction: string
{
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Forwarded = 'forwarded';
    case Accepted = 'accepted';
    case Started = 'started';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
    case CategoryCorrected = 'category_corrected';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Dikirim',
            self::Verified => 'Diverifikasi',
            self::Forwarded => 'Diteruskan',
            self::Accepted => 'Diterima',
            self::Started => 'Mulai diproses',
            self::Resolved => 'Selesai',
            self::Rejected => 'Ditolak',
            self::CategoryCorrected => 'Kategori dikoreksi',
        };
    }
}
