<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['letter_request_id', 'uploaded_by', 'requirement_label', 'disk', 'path', 'original_name', 'mime', 'size_bytes'])]
class LetterAttachment extends Model
{
    public const UPDATED_AT = null;

    public function letterRequest(): BelongsTo
    {
        return $this->belongsTo(LetterRequest::class);
    }
}
