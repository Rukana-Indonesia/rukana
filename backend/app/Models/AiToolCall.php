<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'feature', 'user_id', 'ai_conversation_id', 'subject_type', 'subject_id', 'tool_name',
    'arguments', 'result', 'status', 'duration_ms',
])]
class AiToolCall extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
