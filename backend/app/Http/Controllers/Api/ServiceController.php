<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceTypeResource;
use App\Models\ServiceType;
use App\Models\Village;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Katalog layanan surat. Publik, supaya warga tahu syaratnya sebelum mengajukan (UAT 02). */
class ServiceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ServiceTypeResource::collection($this->services()->orderBy('sort_order')->orderBy('name')->get());
    }

    public function show(string $slug): ServiceTypeResource
    {
        return new ServiceTypeResource($this->services()->where('slug', $slug)->firstOrFail());
    }

    private function services(): Builder
    {
        // MVP: satu desa (sama seperti RegionController).
        return ServiceType::where('village_id', Village::query()->value('id'))->where('is_active', true);
    }
}
