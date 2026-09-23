<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['complaint_id', 'disk', 'path', 'mime', 'size_bytes', 'width', 'height'])]
class ComplaintPhoto extends Model
{
    public const UPDATED_AT = null;

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }
}
