<?php

namespace App\Support\Stats;

use App\Enums\LetterStatus;
use App\Models\Complaint;
use App\Models\LetterRequest;
use App\Models\User;
use App\Support\Scope\RegionScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ekspor rekap surat dan laporan per periode ke Excel/PDF (PRD 6.10, UAT 13).
 * Isinya: ringkasan (angka yang sama dengan dasbor, dari ServiceRecap), rincian
 * per wilayah, dan daftar tiket. Berkas ini dibawa keluar sistem untuk rapat, jadi
 * datanya diminimalkan: tanpa NIK, nomor HP, isi keperluan, maupun nama pelapor
 * laporan (lihat ComplaintPolicy::viewReporter). Nama pemohon surat tetap ada
 * karena register pengantar RT/RW memang mencatatnya.
 */
class RecapExport
{
    /** PDF untuk dibaca/dicetak; daftar yang lebih panjang dipotong (lengkapnya di Excel). */
    public const int PDF_ROW_LIMIT = 300;

    public const array LETTER_HEADERS = [
        'No', 'Kode tiket', 'Tanggal diajukan', 'Layanan', 'RT', 'RW', 'Pemohon', 'Status',
        'Posisi', 'Tanggal selesai', 'Lama proses (hari)', 'Rating',
    ];

    public const array COMPLAINT_HEADERS = [
        'No', 'Kode tiket', 'Tanggal masuk', 'Kategori', 'Judul', 'RT', 'RW', 'Ditangani',
        'Status', 'Urgensi', 'Tanggal selesai', 'Lama penyelesaian (hari)', 'Rating',
    ];

    public function __construct(private ServiceRecap $recap) {}

    public function download(string $format, RegionScope $scope, Period $period, User $by): Response
    {
        $summary = $this->recap->summary($scope, $period);
        $letters = $this->recap->letters($scope, $period)
            ->with(['serviceType', 'user', 'rt', 'rw', 'rating'])
            ->orderBy('submitted_at')->orderBy('id')
            ->get();
        $complaints = $this->recap->complaints($scope, $period)
            ->with(['category', 'rt', 'rw', 'rating'])
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $data = [
            'summary' => $summary,
            'letters' => $this->letterRows($letters),
            'complaints' => $this->complaintRows($complaints),
            'generatedAt' => now(config('app.display_timezone')),
            'generatedBy' => "{$by->name} ({$by->role->label()})",
        ];
        $filename = 'rekap-rukana-'.Str::slug($summary['scope']['label']).'-'.Str::slug($period->label()).'.'.$format;

        return $format === 'pdf' ? $this->pdf($data, $filename) : $this->xlsx($data, $filename);
    }

    /** Baris daftar surat dengan nilai bertipe (tanggal sebagai DateTime, angka sebagai angka). */
    private function letterRows(Collection $letters): Collection
    {
        return $letters->values()->map(fn (LetterRequest $letter, int $i) => [
            $i + 1,
            $letter->code,
            $this->local($letter->submitted_at),
            $letter->serviceType->name,
            $letter->rt->label(),
            $letter->rw->label(),
            $letter->user->name,
            $letter->status->label(),
            match (true) {
                $letter->status === LetterStatus::Rejected => 'Ditolak di '.$letter->rejected_level?->label(),
                $letter->status === LetterStatus::Cancelled => 'Dibatalkan pemohon',
                $letter->current_level !== null => 'Di '.$letter->current_level->label(),
                default => '—',
            },
            $this->local($letter->completed_at),
            $this->days($letter->submitted_at, $letter->completed_at),
            $letter->rating?->score,
        ]);
    }

    private function complaintRows(Collection $complaints): Collection
    {
        return $complaints->values()->map(fn (Complaint $complaint, int $i) => [
            $i + 1,
            $complaint->code,
            $this->local($complaint->created_at),
            $complaint->category->name,
            $complaint->title,
            $complaint->rt->label(),
            $complaint->rw->label(),
            $complaint->handling_level->label(),
            $complaint->status->label(),
            $complaint->urgency?->label(),
            $this->local($complaint->resolved_at),
            $this->days($complaint->created_at, $complaint->resolved_at),
            $complaint->rating?->score,
        ]);
    }

    private function xlsx(array $data, string $filename): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'rekap');
        $writer = new Writer;
        $writer->openToFile($path);

        $title = new Style(fontBold: true, fontSize: 14);
        $bold = new Style(fontBold: true);
        $muted = new Style(fontColor: '5A7A7E');
        $header = new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '34613A', shouldWrapText: true, cellAlignment: CellAlignment::CENTER);
        $date = new Style(format: 'dd/mm/yyyy hh:mm');
        $decimal = new Style(format: '0.0');
        $summary = $data['summary'];
        $l = $summary['letters'];
        $c = $summary['complaints'];

        // Lembar 1: ringkasan, susunannya sama dengan PDF.
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Ringkasan');
        $sheet->setColumnWidth(42, 1);
        $sheet->setColumnWidth(18, 2);
        $writer->addRows([
            Row::fromValuesWithStyle(['Rekap Layanan Rukana — '.$summary['scope']['label']], $title),
            Row::fromValues(['Periode', $summary['period']['label']]),
            Row::fromValuesWithStyle(['Dibuat '.$data['generatedAt']->translatedFormat('j F Y H.i').' WIB oleh '.$data['generatedBy']], $muted),
            Row::fromValuesWithStyle(['Surat/laporan dihitung dari yang masuk dalam periode; rating dari yang diberikan dalam periode.'], $muted),
            Row::fromValues([]),
            Row::fromValuesWithStyle(['Surat pengantar', 'Jumlah'], $header),
            Row::fromValues(['Surat masuk', $l['total']]),
            Row::fromValues(['Selesai', $l['completed']]),
            Row::fromValues(['Masih berjalan', $l['active']]),
            Row::fromValues(['Ditolak', $l['rejected']]),
            Row::fromValuesWithStyles(['Rata-rata waktu proses (hari)', $l['avg_process_days']], [1 => $decimal]),
            Row::fromValues([]),
            Row::fromValuesWithStyle(['Laporan warga', 'Jumlah'], $header),
            Row::fromValues(['Laporan masuk', $c['total']]),
            Row::fromValues(['Selesai', $c['resolved']]),
            Row::fromValues(['Masih berjalan', $c['active']]),
            Row::fromValues(['Ditolak', $c['rejected']]),
            Row::fromValues(['Diteruskan ke RW/desa', $c['forwarded']]),
            Row::fromValues(['Eskalasi ke desa', $c['escalated_village']['total']]),
            Row::fromValuesWithStyles(['Rata-rata waktu penyelesaian (hari)', $c['avg_resolve_days']], [1 => $decimal]),
            Row::fromValues([]),
            Row::fromValuesWithStyle(['Rating kepuasan', 'Nilai'], $header),
            Row::fromValuesWithStyles(['Rata-rata rating (1–5)', $summary['ratings']['average']], [1 => $decimal]),
            Row::fromValues(['Jumlah rating', $summary['ratings']['total']]),
            Row::fromValues([]),
            Row::fromValuesWithStyle(['Layanan surat terbanyak', 'Jumlah'], $header),
            ...$this->ranking($l['top_services']),
            Row::fromValues([]),
            Row::fromValuesWithStyle(['Kategori laporan terbanyak', 'Jumlah'], $header),
            ...$this->ranking($c['top_categories']),
        ]);

        // Lembar 2: per wilayah. Scope desa: baris per RW lalu per RT.
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Per Wilayah');
        $sheet->setSheetView((new SheetView)->withFreezeRow(2));
        $sheet->setColumnWidth(10, 1, 2);
        $sheet->setColumnWidth(12, 3, 4, 5, 6, 7, 8, 10, 11);
        $sheet->setColumnWidth(26, 9);
        $writer->addRow(Row::fromValuesWithStyle(['RW', 'RT', 'Surat masuk', 'Surat selesai', 'Surat berjalan', 'Laporan masuk', 'Laporan selesai', 'Laporan berjalan', 'Kategori terbanyak', 'Rata-rata rating', 'Jumlah rating'], $header));
        $regionRow = fn (array $r, string $rw, string $rt, ?Style $style = null) => Row::fromValuesWithStyles([
            $rw, $rt,
            $r['letters']['total'], $r['letters']['completed'], $r['letters']['active'],
            $r['complaints']['total'], $r['complaints']['resolved'], $r['complaints']['active'],
            $r['top_category'] ? "{$r['top_category']['name']} ({$r['top_category']['total']})" : '—',
            $r['ratings']['average'], $r['ratings']['total'],
        ], array_replace($style ? array_fill(0, 11, $style) : [], [9 => ($style ?? new Style)->withFormat('0.0')]));
        foreach ($summary['rws']->isEmpty() ? [null] : $summary['rws'] as $rw) {
            if ($rw) {
                $writer->addRow($regionRow($rw, $rw['label'], 'Semua RT', $bold));
            }
            foreach ($summary['rts'] as $rt) {
                if (! $rw || $rt['rw']['id'] === $rw['id']) {
                    $writer->addRow($regionRow($rt, $rt['rw']['label'], $rt['label']));
                }
            }
        }

        // Lembar 3 & 4: daftar tiket.
        foreach ([
            ['Surat', self::LETTER_HEADERS, $data['letters'], [2 => $date, 9 => $date, 10 => $decimal], [6, 14, 17, 28, 9, 9, 24, 16, 16, 17, 12, 8]],
            ['Laporan', self::COMPLAINT_HEADERS, $data['complaints'], [2 => $date, 10 => $date, 11 => $decimal], [6, 14, 17, 22, 36, 9, 9, 11, 13, 10, 17, 14, 8]],
        ] as [$name, $headers, $rows, $styles, $widths]) {
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $sheet->setSheetView((new SheetView)->withFreezeRow(2));
            foreach ($widths as $i => $width) {
                $sheet->setColumnWidth($width, $i + 1);
            }
            $writer->addRow(Row::fromValuesWithStyle($headers, $header));
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValuesWithStyles($row, $styles));
            }
            if ($rows->isEmpty()) {
                $writer->addRow(Row::fromValuesWithStyle(['Tidak ada data dalam periode ini.'], $muted));
            }
        }

        $writer->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private function pdf(array $data, string $filename): Response
    {
        return Pdf::loadView('pdf.recap', [...$data, 'limit' => self::PDF_ROW_LIMIT])
            ->setPaper('a4', 'landscape')
            ->setOption('isFontSubsettingEnabled', true)
            ->download($filename);
    }

    /** @return list<Row> */
    private function ranking(Collection $items): array
    {
        return $items->isEmpty()
            ? [Row::fromValues(['—', 0])]
            : $items->map(fn (array $item) => Row::fromValues([$item['name'], $item['total']]))->all();
    }

    private function local(?CarbonInterface $at): ?CarbonInterface
    {
        return $at?->copy()->setTimezone(config('app.display_timezone'));
    }

    private function days(?CarbonInterface $from, ?CarbonInterface $to): ?float
    {
        return $from && $to ? round(($to->getTimestamp() - $from->getTimestamp()) / 86400, 1) : null;
    }
}
