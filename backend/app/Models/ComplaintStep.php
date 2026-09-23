<?php

namespace App\Models;

use App\Enums\ComplaintAction;
use App\Enums\ComplaintStatus;
use App\Enums\Level;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['complaint_id', 'action', 'from_level', 'to_level', 'from_status', 'to_status', 'actor_id', 'note'])]
class ComplaintStep extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => ComplaintAction::class,
            'from_level' => Level::class,
            'to_level' => Level::class,
            'from_status' => ComplaintStatus::class,
            'to_status' => ComplaintStatus::class,
        ];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
