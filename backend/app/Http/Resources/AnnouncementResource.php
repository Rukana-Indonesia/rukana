<?php

namespace App\Http\Resources;

use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pengumuman untuk warga dan pengurus. `target_rw_id`/`target_rt_id` dipakai form
 * ubah; lampiran dibuka lewat endpoint yang dicek Policy.
 *
 * @mixin Announcement
 */
class AnnouncementResource extends JsonResource
{
    private const STATE_LABELS = ['scheduled' => 'Terjadwal', 'active' => 'Tayang', 'expired' => 'Kedaluwarsa'];

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $state = $this->state();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'target' => [
                'level' => $this->target_level->value,
                'label' => $this->targetLabel(),
                'rw_id' => $this->target_rw_id,
                'rt_id' => $this->target_rt_id,
            ],
            'state' => $state,
            'state_label' => self::STATE_LABELS[$state],
            'author' => $this->whenLoaded('author', fn () => [
                'name' => $this->author->name,
                'role_label' => $this->author->role->label(),
            ]),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn (AnnouncementAttachment $file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'mime' => $file->mime,
                'size_bytes' => $file->size_bytes,
                'is_image' => str_starts_with($file->mime, 'image/'),
                'url' => "/api/announcements/{$this->id}/attachments/{$file->id}",
            ])),
            'attachment_count' => $this->whenCounted('attachments'),
            'published_at' => $this->published_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'manage' => (bool) $user?->can('manage', $this->resource),
            ],
        ];
    }
}
