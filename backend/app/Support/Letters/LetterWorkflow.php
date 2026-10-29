<?php

namespace App\Support\Letters;

use App\Enums\LetterAction;
use App\Enums\LetterFlow;
use App\Enums\LetterStatus;
use App\Enums\Level;
use App\Models\LetterAttachment;
use App\Models\LetterRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\Notifications\Notifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Mesin status surat pengantar (lihat docs/database-schema.md bagian 4). Semua
 * perpindahan status lewat sini supaya timeline (letter_steps) dan audit log
 * selalu tercatat, dan pemohon serta pengurus tingkat berikutnya diberi tahu.
 *
 * Aksi (nama di URL) → [status asal, tingkat pelaku, aksi di timeline].
 */
class LetterWorkflow
{
    public const ACTIONS = ['verify', 'approve', 'receive', 'ready', 'pickup', 'reject'];

    private const TRANSITIONS = [
        'verify' => [LetterStatus::Submitted, Level::Rt, LetterAction::Verified],
        'approve' => [LetterStatus::RtVerified, Level::Rw, LetterAction::Approved],
        'receive' => [LetterStatus::RwApproved, Level::Village, LetterAction::Received],
        'ready' => [LetterStatus::VillageReceived, Level::Village, LetterAction::Ready],
        'pickup' => [LetterStatus::ReadyForPickup, Level::Village, LetterAction::PickedUp],
    ];

    /**
     * Tingkat yang berhak menjalankan aksi ini pada status surat sekarang, atau
     * null bila aksinya tidak berlaku (mis. surat sudah selesai).
     */
    public function levelFor(string $action, LetterRequest $letter): ?Level
    {
        if ($action === 'reject') {
            if ($letter->status === LetterStatus::ReadyForPickup || $letter->status->isFinal()) {
                return null;
            }

            return $letter->status->currentLevel($letter->flow);
        }

        // Jika surat sudah memiliki berkas surat resmi (dikirim digital), tidak ada aksi ambil fisik lagi
        if ($action === 'pickup') {
            $hasOfficial = $letter->relationLoaded('attachments')
                ? $letter->attachments->contains(fn ($a) => $a->uploaded_by !== null && $a->uploaded_by !== $letter->user_id)
                : $letter->attachments()->where('uploaded_by', '!=', $letter->user_id)->exists();

            if ($hasOfficial) {
                return null;
            }
        }

        if ($letter->flow === LetterFlow::Village) {
            return match ($action) {
                'receive' => $letter->status === LetterStatus::Submitted ? Level::Village : null,
                'ready' => $letter->status === LetterStatus::VillageReceived ? Level::Village : null,
                'pickup' => $letter->status === LetterStatus::ReadyForPickup ? Level::Village : null,
                default => null,
            };
        }

        [$from, $level] = self::TRANSITIONS[$action] ?? [null, null];

        return $letter->status === $from ? $level : null;
    }

    /** Aksi yang bisa dijalankan akun ini pada surat ini sekarang (untuk tombol di UI). */
    public function availableActions(User $user, LetterRequest $letter): array
    {
        $level = $user->role->actingLevel();

        if ($level === null || ! $user->can('view', $letter)) {
            return [];
        }

        return array_values(array_filter(self::ACTIONS, fn (string $action) => $this->levelFor($action, $letter) === $level));
    }

    /**
     * Jalankan aksi. Otorisasi (peran + wilayah) sudah dicek Policy; di sini status
     * dicek ulang di dalam kunci baris supaya dua pengurus yang menekan tombol
     * bersamaan tidak membuat langkah ganda.
     */
    public function apply(LetterRequest $letter, string $action, User $actor, ?string $note = null, ?UploadedFile $file = null): LetterRequest
    {
        $stored = null;

        try {
            return DB::transaction(function () use ($letter, $action, $actor, $note, $file, &$stored) {
                $letter = LetterRequest::lockForUpdate()->findOrFail($letter->id);
                $level = $this->levelFor($action, $letter);

                if ($level === null || $level !== $actor->role->actingLevel()) {
                    throw new ConflictHttpException("Surat ini sudah berstatus {$letter->status->label()}.");
                }

                $from = $letter->status;
                if ($action === 'reject') {
                    $to = LetterStatus::Rejected;
                    $stepAction = LetterAction::Rejected;
                } elseif ($action === 'ready' && $file !== null) {
                    // Ketika desa mengunggah berkas scan resmi, surat langsung selesai dan terkirim ke warga
                    $to = LetterStatus::Completed;
                    $stepAction = LetterAction::PickedUp;
                    $note = $note ?: 'Salinan resmi digital diterbitkan dan dikirim ke warga.';
                } else {
                    $to = $this->next($from, $letter->flow);
                    $stepAction = $action === 'receive' && $letter->flow === LetterFlow::Village ? LetterAction::Received : self::TRANSITIONS[$action][2];
                }

                $letter->fill([
                    'status' => $to,
                    'current_level' => $to->currentLevel($letter->flow),
                    'completed_at' => $to === LetterStatus::Completed ? now() : null,
                    'rejected_level' => $to === LetterStatus::Rejected ? $level : null,
                    'rejection_reason' => $to === LetterStatus::Rejected ? $note : null,
                ])->save();

                $letter->steps()->create([
                    'level' => $level,
                    'action' => $stepAction,
                    'from_status' => $from,
                    'to_status' => $to,
                    'actor_id' => $actor->id,
                    'note' => $note,
                ]);

                if ($file) {
                    $path = $file->store("letters/{$letter->id}", 'local');
                    $stored = $path;

                    $letter->attachments()->create([
                        'uploaded_by' => $actor->id,
                        'requirement_label' => 'Dokumen Surat Resmi',
                        'disk' => 'local',
                        'path' => $path,
                        'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                        'mime' => $file->getMimeType(),
                        'size_bytes' => $file->getSize(),
                    ]);
                }

                Audit::log('letter.status_changed', $letter, ['from' => $from->value, 'to' => $to->value], $actor);
                app(Notifier::class)->letterChanged($letter);

                return $letter;
            });
        } catch (Throwable $e) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $e;
        }
    }

    /**
     * Pemohon membatalkan pengajuannya. Otorisasi (pemilik surat) dicek Policy; status
     * dicek ulang di dalam kunci baris supaya pembatalan tidak menimpa surat yang
     * baru saja diselesaikan atau ditolak pengurus.
     */
    public function cancel(LetterRequest $letter, User $actor, ?string $reason = null): LetterRequest
    {
        return DB::transaction(function () use ($letter, $actor, $reason) {
            $letter = LetterRequest::lockForUpdate()->findOrFail($letter->id);

            if ($letter->status->isFinal()) {
                throw new ConflictHttpException("Surat ini sudah berstatus {$letter->status->label()}.");
            }

            $from = $letter->status;
            $level = $letter->current_level;

            $letter->fill(['status' => LetterStatus::Cancelled, 'current_level' => null])->save();

            $letter->steps()->create([
                'level' => null,
                'action' => LetterAction::Cancelled,
                'from_status' => $from,
                'to_status' => LetterStatus::Cancelled,
                'actor_id' => $actor->id,
                'note' => $reason,
            ]);

            Audit::log('letter.cancelled', $letter, ['from' => $from->value], $actor);
            app(Notifier::class)->letterCancelled($letter, $level);

            return $letter;
        });
    }

    /** Status berikutnya: tingkat terakhir alur langsung menyelesaikan surat. */
    private function next(LetterStatus $from, LetterFlow $flow): LetterStatus
    {
        $statuses = $flow->statuses();

        return $statuses[array_search($from, $statuses, true) + 1];
    }
}
