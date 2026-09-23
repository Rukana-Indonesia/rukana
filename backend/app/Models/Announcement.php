<?php

namespace App\Models;

use App\Enums\Level;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'village_id', 'author_id', 'title', 'body', 'target_level', 'target_rw_id', 'target_rt_id',
    'published_at', 'expires_at',
])]
class Announcement extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'target_level' => Level::class,
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function targetRw(): BelongsTo
    {
        return $this->belongsTo(Rw::class, 'target_rw_id');
    }

    public function targetRt(): BelongsTo
    {
        return $this->belongsTo(Rt::class, 'target_rt_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AnnouncementAttachment::class);
    }
}
