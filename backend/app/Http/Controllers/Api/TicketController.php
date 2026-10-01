<?php

namespace App\Http\Controllers\Api;

use App\Enums\LetterStatus;
use App\Http\Controllers\Controller;
use App\Models\LetterRequest;
use App\Models\LetterStep;
use Illuminate\Http\JsonResponse;

/**
 * Cek status lewat kode tiket, tanpa login (UAT 08). Hanya status dan tahapan:
 * tanpa nama, no. HP, NIK, isi keperluan, nama pengurus, maupun alasan penolakan.
 */
class TicketController extends Controller
{
    public function show(string $code): JsonResponse
    {
        $code = strtoupper(trim($code));

        // Laporan warga (LPR-…) menyusul di Fase 3.
        $letter = LetterRequest::with(['serviceType', 'steps'])->where('code', $code)->first();
        abort_if($letter === null, 404, 'Kode tiket tidak ditemukan. Periksa kembali penulisannya.');

        return response()->json(['data' => [
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
        ]]);
    }
}
