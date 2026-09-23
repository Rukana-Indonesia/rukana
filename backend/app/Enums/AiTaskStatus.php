<?php

namespace App\Enums;

enum AiTaskStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu',
            self::Done => 'Selesai',
            self::Failed => 'Gagal',
        };
    }
}
