<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['announcement_id', 'disk', 'path', 'original_name', 'mime', 'size_bytes'])]
class AnnouncementAttachment extends Model
{
    public const UPDATED_AT = null;

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
