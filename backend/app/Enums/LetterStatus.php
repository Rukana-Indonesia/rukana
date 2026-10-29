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
    case Cancelled = 'cancelled';

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
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, self::finals(), true);
    }

    /** Status akhir: surat tidak lagi ada di antrean siapa pun. */
    public static function finals(): array
    {
        return [self::Completed, self::Rejected, self::Cancelled];
    }

    /** Tingkat yang harus bertindak pada status ini; null bila sudah final. */
    public function currentLevel(?LetterFlow $flow = null): ?Level
    {
        return match ($this) {
            self::Submitted => $flow === LetterFlow::Village ? Level::Village : Level::Rt,
            self::RtVerified => Level::Rw,
            self::RwApproved, self::VillageReceived, self::ReadyForPickup => Level::Village,
            self::Completed, self::Rejected, self::Cancelled => null,
        };
    }

    /** Status yang menunggu tindakan tingkat ini (antrean kerja pengurus). */
    public static function awaiting(Level $level): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->currentLevel() === $level));
    }
}
