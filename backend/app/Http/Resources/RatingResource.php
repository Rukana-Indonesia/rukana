<?php

namespace App\Http\Resources;

use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rating di detail surat/laporan. Hanya terlihat oleh pihak yang boleh membuka
 * tiketnya (pemohon/pelapor dan pengurus di wilayahnya).
 *
 * @mixin Rating
 */
class RatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'score' => $this->score,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
