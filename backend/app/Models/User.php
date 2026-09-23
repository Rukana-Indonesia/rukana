<?php

namespace App\Models;

use App\Enums\ResidencyStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Support\Scope\RegionScope;
use App\Support\Scope\ScopeResolver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name', 'phone', 'password', 'role', 'village_id', 'rw_id', 'rt_id',
    'residency_status', 'status', 'must_change_password',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'status' => UserStatus::class,
            'residency_status' => ResidencyStatus::class,
            'must_change_password' => 'boolean',
            'verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    public function rw(): BelongsTo
    {
        return $this->belongsTo(Rw::class);
    }

    public function rt(): BelongsTo
    {
        return $this->belongsTo(Rt::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function letterRequests(): HasMany
    {
        return $this->hasMany(LetterRequest::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class, 'reporter_id');
    }

    public function scope(): RegionScope
    {
        return app(ScopeResolver::class)->for($this);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}
