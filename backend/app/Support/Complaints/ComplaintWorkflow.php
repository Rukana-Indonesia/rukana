<?php

namespace App\Support\Complaints;

use App\Enums\ComplaintAction;
use App\Enums\ComplaintStatus;
use App\Enums\Level;
use App\Enums\Urgency;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\User;
use App\Support\Audit;
use App\Support\Notifications\Notifier;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Mesin status laporan warga (lihat docs/database-schema.md bagian 5). Semua
 * perpindahan status dan koreksi kategori lewat sini supaya timeline
 * (complaint_steps) dan audit log selalu tercatat.
 *
 * Hanya tingkat yang sedang memegang laporan (`handling_level`) yang bisa
 * bertindak. Laporan baru selalu dipegang RT pelapor.
 */
class ComplaintWorkflow
{
    public const ACTIONS = ['verify', 'start', 'accept', 'forward', 'resolve', 'reject'];

    /** Aksi (nama di URL) → status asal yang boleh. */
    private const FROM = [
        'verify' => [ComplaintStatus::Submitted],
        'start' => [ComplaintStatus::Verified],
        'accept' => [ComplaintStatus::Forwarded],
        'forward' => [ComplaintStatus::Verified, ComplaintStatus::Forwarded, ComplaintStatus::InProgress],
        'resolve' => [ComplaintStatus::InProgress],
        'reject' => [ComplaintStatus::Submitted, ComplaintStatus::Verified, ComplaintStatus::Forwarded],
    ];

    private const RESULT = [
        'verify' => [ComplaintStatus::Verified, ComplaintAction::Verified],
        'start' => [ComplaintStatus::InProgress, ComplaintAction::Started],
        'accept' => [ComplaintStatus::InProgress, ComplaintAction::Accepted],
        'forward' => [ComplaintStatus::Forwarded, ComplaintAction::Forwarded],
        'resolve' => [ComplaintStatus::Resolved, ComplaintAction::Resolved],
        'reject' => [ComplaintStatus::Rejected, ComplaintAction::Rejected],
    ];

    /**
     * Tingkat yang berhak menjalankan aksi ini pada laporan sekarang, atau null
     * bila aksinya tidak berlaku (mis. laporan sudah selesai, atau desa mencoba
     * meneruskan ke atas).
     */
    public function levelFor(string $action, Complaint $complaint): ?Level
    {
        if (! in_array($complaint->status, self::FROM[$action] ?? [], true)) {
            return null;
        }

        if ($action === 'forward' && $this->forwardTargets($complaint->handling_level) === []) {
            return null;
        }

        return $complaint->handling_level;
    }

    /** Aksi yang bisa dijalankan akun ini pada laporan ini sekarang (untuk tombol di UI). */
    public function availableActions(User $user, Complaint $complaint): array
    {
        $level = $user->role->actingLevel();

        if ($level === null || ! $user->can('view', $complaint)) {
            return [];
        }

        return array_values(array_filter(self::ACTIONS, fn (string $action) => $this->levelFor($action, $complaint) === $level));
    }

    /** Laporan hanya bisa diteruskan ke tingkat di atasnya: RT → RW/desa, RW → desa. */
    public function forwardTargets(Level $from): array
    {
        return match ($from) {
            Level::Rt => [Level::Rw, Level::Village],
            Level::Rw => [Level::Village],
            Level::Village => [],
        };
    }

    /**
     * Jalankan aksi. Otorisasi (peran + wilayah) sudah dicek Policy; di sini status
     * dicek ulang di dalam kunci baris supaya dua pengurus yang menekan tombol
     * bersamaan tidak membuat langkah ganda.
     */
    public function apply(Complaint $complaint, string $action, User $actor, ?string $note = null, ?Level $target = null): Complaint
    {
        return DB::transaction(function () use ($complaint, $action, $actor, $note, $target) {
            $complaint = Complaint::lockForUpdate()->findOrFail($complaint->id);
            $level = $this->levelFor($action, $complaint);

            if ($level === null || $level !== $actor->role->actingLevel()) {
                throw new ConflictHttpException("Laporan ini sudah berstatus {$complaint->status->label()}.");
            }

            if ($action === 'forward' && ! in_array($target, $this->forwardTargets($level), true)) {
                throw new ConflictHttpException('Laporan hanya bisa diteruskan ke tingkat di atas Anda.');
            }

            $from = $complaint->status;
            [$to, $stepAction] = self::RESULT[$action];
            $toLevel = $action === 'forward' ? $target : $level;

            $complaint->fill([
                'status' => $to,
                'handling_level' => $toLevel,
                'verified_at' => $action === 'verify' ? now() : $complaint->verified_at,
                'resolved_at' => $to === ComplaintStatus::Resolved ? now() : null,
                'rejection_reason' => $to === ComplaintStatus::Rejected ? $note : null,
            ])->save();

            $complaint->steps()->create([
                'action' => $stepAction,
                'from_level' => $level,
                'to_level' => $toLevel,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'note' => $note,
            ]);

            Audit::log('complaint.status_changed', $complaint, array_filter([
                'from' => $from->value,
                'to' => $to->value,
                'to_level' => $action === 'forward' ? $toLevel->value : null,
            ]), $actor);
            app(Notifier::class)->complaintChanged($complaint, $action, $note);

            return $complaint;
        });
    }

    /**
     * Koreksi kategori dan/atau tetapkan urgensi oleh tingkat yang memegang laporan.
     * Pilihan kategori pelapor (`reported_category_id`) tidak pernah diubah, supaya
     * koreksi bisa dibandingkan nanti (evaluasi Smart Complaint).
     */
    public function classify(Complaint $complaint, User $actor, ComplaintCategory $category, ?Urgency $urgency): Complaint
    {
        return DB::transaction(function () use ($complaint, $actor, $category, $urgency) {
            $complaint = Complaint::with('category')->lockForUpdate()->findOrFail($complaint->id);

            if ($complaint->status->isFinal() || $complaint->handling_level !== $actor->role->actingLevel()) {
                throw new ConflictHttpException("Laporan ini sudah berstatus {$complaint->status->label()}.");
            }

            $old = $complaint->category;
            $complaint->fill(['category_id' => $category->id, 'urgency' => $urgency])->save();
            $complaint->unsetRelation('category');

            if ($old->id !== $category->id) {
                $complaint->steps()->create([
                    'action' => ComplaintAction::CategoryCorrected,
                    'from_level' => $complaint->handling_level,
                    'to_level' => $complaint->handling_level,
                    'from_status' => $complaint->status,
                    'to_status' => $complaint->status,
                    'actor_id' => $actor->id,
                    'note' => "{$old->name} → {$category->name}",
                ]);
            }

            Audit::log('complaint.classified', $complaint, [
                'category_id' => $category->id,
                'previous_category_id' => $old->id,
                'urgency' => $urgency?->value,
            ], $actor);

            return $complaint;
        });
    }

    /**
     * Tahapan untuk progres di UI dan cek tiket. "Diteruskan" hanya muncul bila
     * laporan memang pernah diteruskan.
     */
    public function stages(Complaint $complaint, bool $forwarded): array
    {
        $statuses = array_filter(
            [ComplaintStatus::Submitted, ComplaintStatus::Verified, ComplaintStatus::Forwarded, ComplaintStatus::InProgress, ComplaintStatus::Resolved],
            fn (ComplaintStatus $s) => $s !== ComplaintStatus::Forwarded || $forwarded || $complaint->status === ComplaintStatus::Forwarded,
        );

        return array_values(array_map(fn (ComplaintStatus $s) => ['status' => $s->value, 'label' => $s->label()], $statuses));
    }
}
