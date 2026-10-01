<?php

namespace App\Http\Resources;

use App\Enums\LetterStatus;
use App\Models\LetterAttachment;
use App\Models\LetterRequest;
use App\Models\LetterStep;
use App\Support\Letters\LetterWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Surat pengantar untuk pemohon dan pengurus di wilayahnya. NIK utuh tidak
 * pernah ikut di sini; operator membukanya lewat endpoint terpisah yang diaudit.
 *
 * @mixin LetterRequest
 */
class LetterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'current_level' => $this->current_level?->value,
            'current_level_label' => $this->current_level?->label(),
            'flow' => $this->flow->value,
            'flow_label' => $this->flow->label(),
            'stages' => array_map(fn (LetterStatus $s) => ['status' => $s->value, 'label' => $s->label()], $this->flow->statuses()),
            'service' => $this->whenLoaded('serviceType', fn () => [
                'id' => $this->serviceType->id,
                'slug' => $this->serviceType->slug,
                'name' => $this->serviceType->name,
                'requirements' => $this->serviceType->requirements ?? [],
                'notes' => $this->serviceType->notes,
            ]),
            'purpose' => $this->purpose,
            'form_data' => $this->form_data ?? [],
            'nik_masked' => $this->nik_masked,
            'rejected_level' => $this->rejected_level?->value,
            'rejected_level_label' => $this->rejected_level?->label(),
            'rejection_reason' => $this->rejection_reason,
            'applicant' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
            ]),
            'rt' => $this->whenLoaded('rt', fn () => ['id' => $this->rt->id, 'label' => $this->rt->label()]),
            'rw' => $this->whenLoaded('rw', fn () => ['id' => $this->rw->id, 'label' => $this->rw->label()]),
            'steps' => $this->whenLoaded('steps', fn () => $this->steps->map(fn (LetterStep $step) => [
                'id' => $step->id,
                'action' => $step->action->value,
                'action_label' => $step->action->label(),
                'level' => $step->level?->value,
                'level_label' => $step->level?->label(),
                'to_status' => $step->to_status->value,
                'to_status_label' => $step->to_status->label(),
                'note' => $step->note,
                'actor' => $step->actor ? ['name' => $step->actor->name, 'role_label' => $step->actor->role->label()] : null,
                'created_at' => $step->created_at?->toIso8601String(),
            ])),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn (LetterAttachment $file) => [
                'id' => $file->id,
                'label' => $file->requirement_label,
                'name' => $file->original_name,
                'mime' => $file->mime,
                'size_bytes' => $file->size_bytes,
                'url' => "/api/letters/{$this->id}/attachments/{$file->id}",
            ])),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'actions' => $user ? app(LetterWorkflow::class)->availableActions($user, $this->resource) : [],
                'print' => (bool) $user?->can('print', $this->resource),
                'view_nik' => (bool) $user?->can('viewNik', $this->resource),
            ],
        ];
    }
}
