<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'residency_status' => $this->residency_status?->value,
            'rejection_reason' => $this->rejection_reason,
            'must_change_password' => $this->must_change_password,
            'village' => $this->whenLoaded('village', fn () => ['id' => $this->village->id, 'name' => $this->village->name]),
            'rw' => $this->whenLoaded('rw', fn () => $this->rw ? ['id' => $this->rw->id, 'number' => $this->rw->number, 'label' => $this->rw->label()] : null),
            'rt' => $this->whenLoaded('rt', fn () => $this->rt ? ['id' => $this->rt->id, 'number' => $this->rt->number, 'label' => $this->rt->label()] : null),
            'scope' => $this->when($request->user()?->is($this->resource), fn () => $this->scope()->toArray()),
        ];
    }
}
