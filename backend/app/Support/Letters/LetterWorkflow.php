<?php

namespace App\Support\Letters;

use App\Enums\LetterAction;
use App\Enums\LetterFlow;
use App\Enums\LetterStatus;
use App\Enums\Level;
use App\Models\LetterRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Mesin status surat pengantar (lihat docs/database-schema.md bagian 4). Semua
 * perpindahan status lewat sini supaya timeline (letter_steps) dan audit log
 * selalu tercatat.
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
            return $letter->status->currentLevel();
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
    public function apply(LetterRequest $letter, string $action, User $actor, ?string $note = null): LetterRequest
    {
        return DB::transaction(function () use ($letter, $action, $actor, $note) {
            $letter = LetterRequest::lockForUpdate()->findOrFail($letter->id);
            $level = $this->levelFor($action, $letter);

            if ($level === null || $level !== $actor->role->actingLevel()) {
                throw new ConflictHttpException("Surat ini sudah berstatus {$letter->status->label()}.");
            }

            $from = $letter->status;
            $to = $action === 'reject' ? LetterStatus::Rejected : $this->next($from, $letter->flow);
            $stepAction = $action === 'reject' ? LetterAction::Rejected : self::TRANSITIONS[$action][2];

            $letter->fill([
                'status' => $to,
                'current_level' => $to->currentLevel(),
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

            Audit::log('letter.status_changed', $letter, ['from' => $from->value, 'to' => $to->value], $actor);

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
