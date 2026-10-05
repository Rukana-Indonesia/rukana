<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComplaintAction;
use App\Enums\ComplaintStatus;
use App\Enums\LetterStatus;
use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\ComplaintStep;
use App\Models\LetterRequest;
use App\Models\LetterStep;
use App\Support\Complaints\ComplaintWorkflow;
use App\Support\TicketCode;
use Illuminate\Http\JsonResponse;

/**
 * Cek status lewat kode tiket, tanpa login (UAT 08). Hanya status dan tahapan:
 * tanpa nama, no. HP, NIK, isi keperluan/laporan, lokasi, foto, nama pengurus,
 * maupun alasan penolakan.
 */
class TicketController extends Controller
{
    public function show(string $code): JsonResponse
    {
        $code = strtoupper(trim($code));

        $data = str_starts_with($code, TicketCode::COMPLAINT.'-')
            ? $this->complaint($code)
            : $this->letter($code);

        abort_if($data === null, 404, 'Kode tiket tidak ditemukan. Periksa kembali penulisannya.');

        return response()->json(['data' => $data]);
    }

    private function letter(string $code): ?array
    {
        $letter = LetterRequest::with(['serviceType', 'steps'])->where('code', $code)->first();

        return $letter ? [
            'code' => $letter->code,
            'type' => 'letter',
            'type_label' => 'Surat pengantar',
            'title' => $letter->serviceType->name,
            'status' => $letter->status->value,
            'status_label' => $letter->status->label(),
            'current_level_label' => $letter->current_level?->label(),
            'rejected_level_label' => $letter->rejected_level?->label(),
            'stages' => array_map(fn (LetterStatus $s) => ['status' => $s->value, 'label' => $s->label()], $letter->flow->statuses()),
            'steps' => $letter->steps->map(fn (LetterStep $step) => [
                'action' => $step->action->value,
                'action_label' => $step->action->label(),
                'level_label' => $step->level?->label(),
                'to_status' => $step->to_status->value,
                'created_at' => $step->created_at?->toIso8601String(),
            ]),
            'submitted_at' => $letter->submitted_at?->toIso8601String(),
            'completed_at' => $letter->completed_at?->toIso8601String(),
        ] : null;
    }

    /** Judul laporan ditulis warga (bisa memuat nama/alamat), jadi yang tampil nama kategorinya. */
    private function complaint(string $code): ?array
    {
        $complaint = Complaint::with(['category', 'steps'])->where('code', $code)->first();

        if ($complaint === null) {
            return null;
        }

        // Koreksi kategori adalah urusan internal pengurus; tidak ditampilkan.
        $steps = $complaint->steps->reject(fn (ComplaintStep $step) => $step->action === ComplaintAction::CategoryCorrected)->values();
        $final = $complaint->status->isFinal();

        return [
            'code' => $complaint->code,
            'type' => 'complaint',
            'type_label' => 'Laporan warga',
            'title' => $complaint->category->name,
            'status' => $complaint->status->value,
            'status_label' => $complaint->status->label(),
            'current_level_label' => $final ? null : $complaint->handling_level->label(),
            'rejected_level_label' => $complaint->status === ComplaintStatus::Rejected ? $complaint->handling_level->label() : null,
            'stages' => app(ComplaintWorkflow::class)->stages($complaint, count($complaint->handledLevels()) > 1),
            'steps' => $steps->map(fn (ComplaintStep $step) => [
                'action' => $step->action->value,
                'action_label' => $step->action->label(),
                // Eskalasi ditampilkan sebagai tujuannya ("Diteruskan · Desa").
                'level_label' => ($step->action === ComplaintAction::Forwarded ? $step->to_level : $step->from_level ?? $step->to_level)?->label(),
                'to_status' => $step->to_status?->value,
                'created_at' => $step->created_at?->toIso8601String(),
            ]),
            'submitted_at' => $complaint->created_at?->toIso8601String(),
            'completed_at' => $complaint->resolved_at?->toIso8601String(),
        ];
    }
}
