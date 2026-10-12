<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ai_conversation_id', 'role', 'content', 'tools', 'model', 'input_tokens', 'output_tokens'])]
class AiMessage extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['tools' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
