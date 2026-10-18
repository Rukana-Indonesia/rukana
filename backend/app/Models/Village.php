<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'district', 'regency', 'province', 'address', 'map_latitude', 'map_longitude', 'map_zoom'])]
class Village extends Model
{
    protected function casts(): array
    {
        return [
            'map_latitude' => 'float',
            'map_longitude' => 'float',
            'map_zoom' => 'integer',
        ];
    }

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

    /**
     * Titik tengah peta desa (balai desa), diatur operator. Selama belum diatur
     * dipakai titik cadangan dari config, dan `configured` bernilai false.
     *
     * @return array{latitude: float, longitude: float, zoom: int, configured: bool}
     */
    public function mapCenter(): array
    {
        $configured = $this->map_latitude !== null && $this->map_longitude !== null;
        $fallback = config('app.map_center');

        return [
            'latitude' => $configured ? $this->map_latitude : $fallback['latitude'],
            'longitude' => $configured ? $this->map_longitude : $fallback['longitude'],
            'zoom' => $this->map_zoom ?? $fallback['zoom'],
            'configured' => $configured,
        ];
    }
}
