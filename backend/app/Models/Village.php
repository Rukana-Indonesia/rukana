<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'district', 'regency', 'province', 'address'])]
class Village extends Model
{
    public function rws(): HasMany
    {
        return $this->hasMany(Rw::class)->orderBy('number');
    }

    public function rts(): HasMany
    {
        return $this->hasMany(Rt::class);
    }

    public function serviceTypes(): HasMany
    {
        return $this->hasMany(ServiceType::class);
    }
}
