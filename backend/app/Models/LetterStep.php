<?php

namespace App\Models;

use App\Enums\LetterAction;
use App\Enums\LetterStatus;
use App\Enums\Level;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['letter_request_id', 'level', 'action', 'from_status', 'to_status', 'actor_id', 'note'])]
class LetterStep extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'level' => Level::class,
            'action' => LetterAction::class,
            'from_status' => LetterStatus::class,
            'to_status' => LetterStatus::class,
        ];
    }

    public function letterRequest(): BelongsTo
    {
        return $this->belongsTo(LetterRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
