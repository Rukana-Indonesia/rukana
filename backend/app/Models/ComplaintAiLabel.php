<?php

namespace App\Models;

use App\Enums\AiTaskStatus;
use App\Enums\Level;
use App\Enums\Urgency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'complaint_id', 'status', 'model', 'prompt_version', 'suggested_category_id',
    'suggested_urgency', 'suggested_level', 'summary', 'raw_output', 'error',
    'final_category_id', 'final_urgency', 'final_level', 'reviewed_by', 'reviewed_at',
])]
class ComplaintAiLabel extends Model
{
    protected function casts(): array
    {
        return [
            'status' => AiTaskStatus::class,
            'suggested_urgency' => Urgency::class,
            'suggested_level' => Level::class,
            'final_urgency' => Urgency::class,
            'final_level' => Level::class,
            'raw_output' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(ComplaintCategory::class, 'suggested_category_id');
    }

    public function finalCategory(): BelongsTo
    {
        return $this->belongsTo(ComplaintCategory::class, 'final_category_id');
    }
}
