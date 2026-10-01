<?php

namespace App\Http\Controllers\Api;

use App\Enums\LetterAction;
use App\Http\Controllers\Controller;
use App\Models\LetterRequest;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Pengantar RT/RW sederhana untuk desa yang masih meminta pengantar kertas.
 * Bukan surat resmi desa: tanda tangan basah tetap di RT/RW.
 */
class LetterPdfController extends Controller
{
    public function __invoke(LetterRequest $letter): Response
    {
        Gate::authorize('print', $letter);

        $letter->load(['serviceType', 'user', 'rt', 'rw', 'village', 'steps.actor']);
        $step = fn (LetterAction $action) => $letter->steps->firstWhere('action', $action);

        Audit::log('letter.printed', $letter);

        return Pdf::loadView('pdf.letter', [
            'letter' => $letter,
            'verified' => $step(LetterAction::Verified),
            'approved' => $step(LetterAction::Approved),
            'checkUrl' => rtrim((string) config('app.frontend_url'), '/').'/cek-tiket?kode='.$letter->code,
        ])
            ->setPaper('a4')
            // Hanya glyph yang dipakai yang ditanam: PDF sekitar 20 KB, bukan 850 KB, untuk HP dengan kuota terbatas.
            ->setOption('isFontSubsettingEnabled', true)
            ->stream("Pengantar-{$letter->code}.pdf");
    }
}
