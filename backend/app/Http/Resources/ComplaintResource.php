<?php

namespace App\Http\Resources;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\ComplaintComment;
use App\Models\ComplaintPhoto;
use App\Models\ComplaintStep;
use App\Models\User;
use App\Support\Complaints\ComplaintWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Laporan warga. Nama dan no. HP pelapor hanya ikut untuk pelapor sendiri dan
 * pengurus yang menangani; pembaca lain melihat "Pelapor" (termasuk di timeline
 * dan tanggapan). Catatan internal hanya untuk pengurus yang menangani.
 *
 * @mixin Complaint
 */
class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $workflow = app(ComplaintWorkflow::class);
        $showReporter = (bool) $user?->can('viewReporter', $this->resource);
        $showInternal = (bool) $user?->can('commentInternal', $this->resource);
        $person = fn (?User $actor) => match (true) {
            $actor === null => null,
            $actor->id === $this->reporter_id && ! $showReporter => ['name' => 'Pelapor', 'role_label' => 'Pelapor', 'is_reporter' => true],
            default => ['name' => $actor->name, 'role_label' => $actor->id === $this->reporter_id ? 'Pelapor' : $actor->role->label(), 'is_reporter' => $actor->id === $this->reporter_id],
        };
        $actions = $user ? $workflow->availableActions($user, $this->resource) : [];

        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'handling_level' => $this->handling_level->value,
            'handling_level_label' => $this->handling_level->label(),
            'urgency' => $this->urgency?->value,
            'urgency_label' => $this->urgency?->label(),
            'category' => $this->whenLoaded('category', fn () => $this->category($this->category)),
            'reported_category' => $this->when(
                $this->reported_category_id !== $this->category_id && $this->relationLoaded('reportedCategory'),
                fn () => $this->category($this->reportedCategory),
            ),
            'location' => [
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
                'text' => $this->location_text,
            ],
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'is_mine' => $user !== null && $user->id === $this->reporter_id,
            'reporter' => $this->when($showReporter && $this->relationLoaded('reporter'), fn () => [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
                'phone' => $this->reporter->phone,
            ]),
            'rt' => $this->whenLoaded('rt', fn () => ['id' => $this->rt->id, 'label' => $this->rt->label()]),
            'rw' => $this->whenLoaded('rw', fn () => ['id' => $this->rw->id, 'label' => $this->rw->label()]),
            'stages' => $this->whenLoaded('steps', fn () => $workflow->stages($this->resource, count($this->handledLevels()) > 1)),
            'steps' => $this->whenLoaded('steps', fn () => $this->steps->map(fn (ComplaintStep $step) => [
                'id' => $step->id,
                'action' => $step->action->value,
                'action_label' => $step->action->label(),
                'from_level_label' => $step->from_level?->label(),
                'to_level' => $step->to_level?->value,
                'to_level_label' => $step->to_level?->label(),
                'to_status' => $step->to_status?->value,
                'note' => $step->note,
                'actor' => $person($step->actor),
                'created_at' => $step->created_at?->toIso8601String(),
            ])),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn (ComplaintPhoto $photo) => [
                'id' => $photo->id,
                'width' => $photo->width,
                'height' => $photo->height,
                'url' => "/api/complaints/{$this->id}/photos/{$photo->id}",
            ])),
            'photo_count' => $this->whenCounted('photos'),
            'comments' => $this->whenLoaded('comments', fn () => $this->comments
                ->filter(fn (ComplaintComment $comment) => $showInternal || ! $comment->is_internal)
                ->values()
                ->map(fn (ComplaintComment $comment) => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'is_internal' => $comment->is_internal,
                    'author' => $person($comment->user),
                    'is_mine' => $user !== null && $comment->user_id === $user->id,
                    'created_at' => $comment->created_at?->toIso8601String(),
                ])),
            'rating' => $this->whenLoaded('rating', fn () => $this->rating ? new RatingResource($this->rating) : null),
            'ai_label' => $this->when(
                $this->relationLoaded('aiLabel') && (bool) $user?->can('viewAiLabel', $this->resource),
                fn () => $this->aiLabel ? new ComplaintAiLabelResource($this->aiLabel) : null,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'actions' => $actions,
                'forward_to' => in_array('forward', $actions, true)
                    ? array_map(fn ($level) => ['value' => $level->value, 'label' => $level->label()], $workflow->forwardTargets($this->handling_level))
                    : [],
                'classify' => (bool) $user?->can('classify', $this->resource),
                'comment' => (bool) $user?->can('comment', $this->resource),
                'comment_internal' => $showInternal,
                'view_reporter' => $showReporter,
                'rate' => (bool) $user?->can('rate', $this->resource),
                // Hanya di detail (aiLabel dimuat); daftar tidak menampilkan tombol ini.
                'retry_ai' => $this->relationLoaded('aiLabel') && (bool) $user?->can('retryAi', $this->resource),
            ],
        ];
    }

    private function category(ComplaintCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'parent_name' => $category->parent?->name,
        ];
    }
}
