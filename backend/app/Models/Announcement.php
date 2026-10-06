<?php

namespace App\Models;

use App\Enums\Level;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pengumuman bertarget (PRD 6.6). Satu pengumuman punya satu target: seluruh desa,
 * satu RW, atau satu RT. Untuk target RT, `target_rw_id` ikut diisi RW induknya
 * supaya pengurus RW bisa melihat pengumuman RT di wilayahnya.
 */
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
            'notified_at' => 'datetime',
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
        return $this->hasMany(AnnouncementAttachment::class)->orderBy('id');
    }

    /**
     * Pengumuman yang ditujukan ke wilayah akun ini. Warga dan pengurus RT melihat
     * pengumuman desa, RW-nya, dan RT-nya; pengurus RW juga melihat pengumuman RT di
     * RW-nya; perangkat desa melihat semuanya. Belum memperhitungkan jadwal terbit.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where('village_id', $user->village_id);

        if ($user->role->isVillageWide()) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->where('target_level', Level::Village)
            ->orWhere(fn (Builder $q) => $user->role === Role::Rw
                ? $q->where('target_rw_id', $user->rw_id)
                : $q->where('target_level', Level::Rw)->where('target_rw_id', $user->rw_id))
            ->orWhere(fn (Builder $q) => $q->where('target_level', Level::Rt)->where('target_rt_id', $user->rt_id)));
    }

    /** Pengumuman yang boleh diubah/dihapus akun ini (lihat AnnouncementPolicy::manage). */
    #[Scope]
    protected function manageableBy(Builder $query, User $user): void
    {
        $query->where('village_id', $user->village_id);

        match ($user->role) {
            Role::Operator => null,
            Role::Rw => $query->where('target_level', Level::Rw)->where('target_rw_id', $user->rw_id),
            Role::Rt => $query->where('target_level', Level::Rt)->where('target_rt_id', $user->rt_id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Sudah terbit dan belum kedaluwarsa. */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('published_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** scheduled (belum terbit), active, atau expired. */
    public function state(): string
    {
        return match (true) {
            $this->published_at->isFuture() => 'scheduled',
            $this->expires_at !== null && ! $this->expires_at->isFuture() => 'expired',
            default => 'active',
        };
    }

    /** Mis. "Seluruh Desa", "RW 04", "RT 02/RW 03". Butuh relasi targetRw/targetRt. */
    public function targetLabel(): string
    {
        return match ($this->target_level) {
            Level::Village => 'Seluruh Desa',
            Level::Rw => $this->targetRw->label(),
            Level::Rt => "{$this->targetRt->label()}/{$this->targetRw->label()}",
        };
    }
}
