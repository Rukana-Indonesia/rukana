<?php

namespace App\Http\Resources;

use App\Models\ComplaintAiLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Saran Smart Complaint untuk pengurus. Keluaran mentah dan versi prompt tidak ikut;
 * itu untuk evaluasi di backend.
 *
 * @mixin ComplaintAiLabel
 */
class ComplaintAiLabelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->relationLoaded('suggestedCategory') ? $this->suggestedCategory : null;

        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'category' => $category ? [
                'id' => $category->id,
                'name' => $category->name,
                'parent_name' => $category->parent?->name,
            ] : null,
            'urgency' => $this->suggested_urgency?->value,
            'urgency_label' => $this->suggested_urgency?->label(),
            'level' => $this->suggested_level?->value,
            'level_label' => $this->suggested_level?->label(),
            'summary' => $this->summary,
            'error' => $this->error,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
