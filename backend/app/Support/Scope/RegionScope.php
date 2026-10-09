<?php

namespace App\Support\Scope;

use App\Enums\Level;
use App\Models\Rt;
use App\Models\Rw;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Wilayah data yang boleh diakses satu akun. Selalu diturunkan dari akun yang login
 * (lihat ScopeResolver), tidak pernah dari input request.
 *
 * $level null berarti scope "milik sendiri" (warga).
 */
final readonly class RegionScope
{
    public function __construct(
        public int $villageId,
        public ?Level $level,
        public ?int $rwId = null,
        public ?int $rtId = null,
        public ?int $userId = null,
    ) {}

    public function isOwnDataOnly(): bool
    {
        return $this->level === null;
    }

    /** ID wilayah sesuai level: id RT, id RW, atau id desa. */
    public function regionId(): ?int
    {
        return match ($this->level) {
            Level::Rt => $this->rtId,
            Level::Rw => $this->rwId,
            Level::Village => $this->villageId,
            null => null,
        };
    }

    /**
     * Batasi query ke scope ini. Tabel target harus punya kolom snapshot
     * village_id/rw_id/rt_id; untuk warga dipakai kolom pemilik ($ownerColumn).
     */
    public function apply(Builder $query, string $ownerColumn = 'user_id', string $table = ''): Builder
    {
        $col = fn (string $c) => $table === '' ? $c : "{$table}.{$c}";

        $query->where($col('village_id'), $this->villageId);

        return match ($this->level) {
            Level::Village => $query,
            Level::Rw => $query->where($col('rw_id'), $this->rwId),
            Level::Rt => $query->where($col('rt_id'), $this->rtId),
            null => $query->where($col($ownerColumn), $this->userId),
        };
    }

    /** Apakah satu record wilayah (snapshot village/rw/rt) masuk scope ini. */
    public function covers(int $villageId, ?int $rwId = null, ?int $rtId = null, ?int $ownerId = null): bool
    {
        if ($villageId !== $this->villageId) {
            return false;
        }

        return match ($this->level) {
            Level::Village => true,
            Level::Rw => $rwId === $this->rwId,
            Level::Rt => $rtId === $this->rtId,
            null => $ownerId !== null && $ownerId === $this->userId,
        };
    }

    /**
     * Scope yang lebih sempit di dalam scope ini, mis. operator memfilter dasbor ke
     * satu RW. Null bila wilayah itu di luar scope ini: filter tidak pernah melebarkan akses.
     */
    public function narrow(?Rw $rw = null, ?Rt $rt = null): ?self
    {
        if ($this->level === null) {
            return null;
        }

        if ($rt) {
            return $this->covers($rt->village_id, $rt->rw_id, $rt->id)
                ? new self($rt->village_id, Level::Rt, $rt->rw_id, $rt->id)
                : null;
        }

        if ($rw) {
            return $this->level !== Level::Rt && $this->covers($rw->village_id, $rw->id)
                ? new self($rw->village_id, Level::Rw, $rw->id)
                : null;
        }

        return $this;
    }

    public function toArray(): array
    {
        return [
            'level' => $this->level?->value ?? 'self',
            'village_id' => $this->villageId,
            'rw_id' => $this->rwId,
            'rt_id' => $this->rtId,
        ];
    }
}
