<?php

namespace App\Http\Resources;

use App\Enums\Level;
use App\Models\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceType */
class ServiceTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'purpose' => $this->purpose,
            'requirements' => $this->requirements ?? [],
            'flow' => $this->flow->value,
            'flow_label' => $this->flow->label(),
            'flow_levels' => array_map(fn (Level $level) => $level->label(), $this->flow->levels()),
            'estimated_days' => $this->estimated_days,
            'notes' => $this->notes,
            'requires_nik' => $this->requires_nik,
            'form_schema' => $this->form_schema ?? [],
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'letters_count' => $this->whenCounted('letterRequests'),
        ];
    }
}
