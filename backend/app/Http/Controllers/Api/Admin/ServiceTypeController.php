<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceTypeRequest;
use App\Http\Resources\ServiceTypeResource;
use App\Models\ServiceType;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Katalog layanan dikelola operator desa. Mengubah alur tidak memengaruhi surat
 * yang sudah berjalan, karena alur disalin ke surat saat diajukan.
 */
class ServiceTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $services = ServiceType::where('village_id', $request->user()->village_id)
            ->withCount('letterRequests')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ServiceTypeResource::collection($services);
    }

    public function store(ServiceTypeRequest $request): JsonResponse
    {
        $villageId = $request->user()->village_id;

        $service = ServiceType::create([
            ...$request->payload(),
            'slug' => $request->uniqueSlug(),
            'village_id' => $villageId,
            'sort_order' => ServiceType::where('village_id', $villageId)->max('sort_order') + 1,
        ]);
        Audit::log('service.created', $service);

        return (new ServiceTypeResource($service->loadCount('letterRequests')))
            ->additional(['message' => "Layanan {$service->name} ditambahkan ke katalog."])
            ->response()
            ->setStatusCode(201);
    }

    public function update(ServiceTypeRequest $request, ServiceType $serviceType): ServiceTypeResource
    {
        Gate::authorize('manage-service', $serviceType);

        $serviceType->update($request->payload());
        Audit::log('service.updated', $serviceType, ['changed' => array_keys($serviceType->getChanges())]);

        return (new ServiceTypeResource($serviceType->loadCount('letterRequests')))
            ->additional(['message' => "Layanan {$serviceType->name} diperbarui."]);
    }

    /** Hanya layanan yang belum pernah diajukan yang bisa dihapus; sisanya dinonaktifkan. */
    public function destroy(ServiceType $serviceType): JsonResponse
    {
        Gate::authorize('manage-service', $serviceType);

        abort_if(
            $serviceType->letterRequests()->exists(),
            409,
            'Layanan ini sudah pernah diajukan warga. Nonaktifkan saja supaya riwayatnya tetap tersimpan.',
        );

        $serviceType->delete();
        Audit::log('service.deleted', $serviceType);

        return response()->json(['message' => "Layanan {$serviceType->name} dihapus."]);
    }
}
