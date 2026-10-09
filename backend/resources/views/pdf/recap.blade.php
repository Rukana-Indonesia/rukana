@php
    $l = $summary['letters'];
    $c = $summary['complaints'];
    $r = $summary['ratings'];
    $num = fn ($v) => $v === null ? '—' : number_format($v, 1, ',', '.');
    $cell = fn ($v) => match (true) {
        $v instanceof \DateTimeInterface => $v->format('d/m/Y H.i'),
        is_float($v) => $num($v),
        $v === null || $v === '' => '—',
        default => $v,
    };
    $regionRows = [];
    foreach ($summary['rws']->isEmpty() ? [null] : $summary['rws'] as $rw) {
        if ($rw) {
            $regionRows[] = ['rw' => $rw['label'], 'rt' => 'Semua RT', 'row' => $rw, 'total' => true];
        }
        foreach ($summary['rts'] as $rt) {
            if (! $rw || $rt['rw']['id'] === $rw['id']) {
                $regionRows[] = ['rw' => $rt['rw']['label'], 'rt' => $rt['label'], 'row' => $rt, 'total' => false];
            }
        }
    }
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekap Layanan {{ $summary['scope']['label'] }} — {{ $summary['period']['label'] }}</title>
    <style>
        @page { margin: 1.4cm 1.4cm 1.6cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; line-height: 1.35; color: #001b1a; }
        h1 { font-size: 14pt; margin: 0; }
        h2 { font-size: 10.5pt; margin: 16px 0 6px; color: #34613a; }
        .meta { margin: 3px 0 0; color: #5a7a7e; }
        .note { color: #5a7a7e; font-size: 7.5pt; margin: 4px 0 0; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #34613a; color: #fff; font-weight: bold; text-align: left; padding: 4px 5px; }
        td { padding: 3px 5px; border-bottom: 1px solid #dbdbdb; vertical-align: top; }
        td.n, th.n { text-align: right; }
        table.list td { white-space: nowrap; }
        table.list td.wrap { white-space: normal; }
        tr.total td { font-weight: bold; background: #f1fae6; }
        table.cards td { width: 25%; border: 0; padding: 0 6px 0 0; }
        .card { border: 1px solid #dbdbdb; border-radius: 6px; padding: 8px 10px; }
        .card .label { color: #5a7a7e; }
        .card .value { font-size: 15pt; font-weight: bold; margin-top: 2px; }
        .card .sub { color: #5a7a7e; font-size: 7.5pt; }
        table.split > tbody > tr > td { width: 50%; border: 0; padding: 0 8px 0 0; }
        .page-break { page-break-before: always; }
        .footer { position: fixed; bottom: -1cm; left: 0; right: 0; font-size: 7pt; color: #5a7a7e; border-top: 1px solid #dbdbdb; padding-top: 3px; }
    </style>
</head>
<body>
    <div class="footer">Rukana · Rekap {{ $summary['scope']['label'] }} · {{ $summary['period']['label'] }} · Dibuat {{ $generatedAt->translatedFormat('j F Y H.i') }} WIB oleh {{ $generatedBy }}</div>

    <h1>Rekap Layanan — {{ $summary['scope']['label'] }}</h1>
    <p class="meta">Periode: {{ $summary['period']['label'] }} ({{ \Illuminate\Support\Carbon::parse($summary['period']['from'])->translatedFormat('j F Y') }} – {{ \Illuminate\Support\Carbon::parse($summary['period']['to'])->translatedFormat('j F Y') }})</p>
    <p class="note">Surat dan laporan dihitung dari yang masuk dalam periode; rating dari yang diberikan dalam periode. Daftar laporan tidak memuat identitas pelapor.</p>

    <h2>Ringkasan</h2>
    <table class="cards">
        <tr>
            <td><div class="card">
                <div class="label">Surat masuk</div>
                <div class="value">{{ $l['total'] }}</div>
                <div class="sub">{{ $l['completed'] }} selesai · {{ $l['active'] }} berjalan · {{ $l['rejected'] }} ditolak</div>
            </div></td>
            <td><div class="card">
                <div class="label">Rata-rata waktu proses surat</div>
                <div class="value">{{ $num($l['avg_process_days']) }}@if ($l['avg_process_days'] !== null) <span class="sub">hari</span>@endif</div>
                <div class="sub">dari {{ $l['completed'] }} surat selesai</div>
            </div></td>
            <td><div class="card">
                <div class="label">Laporan masuk</div>
                <div class="value">{{ $c['total'] }}</div>
                <div class="sub">{{ $c['resolved'] }} selesai · {{ $c['active'] }} berjalan · {{ $c['rejected'] }} ditolak</div>
            </div></td>
            <td><div class="card">
                <div class="label">Rata-rata waktu penyelesaian laporan</div>
                <div class="value">{{ $num($c['avg_resolve_days']) }}@if ($c['avg_resolve_days'] !== null) <span class="sub">hari</span>@endif</div>
                <div class="sub">dari {{ $c['resolved'] }} laporan selesai</div>
            </div></td>
        </tr>
    </table>
    <table class="cards" style="margin-top: 8px;">
        <tr>
            <td><div class="card">
                <div class="label">Laporan diteruskan ke RW/desa</div>
                <div class="value">{{ $c['forwarded'] }}</div>
            </div></td>
            <td><div class="card">
                <div class="label">Eskalasi ke desa</div>
                <div class="value">{{ $c['escalated_village']['total'] }}</div>
                <div class="sub">{{ $c['escalated_village']['by_status']['resolved'] }} selesai</div>
            </div></td>
            <td><div class="card">
                <div class="label">Rata-rata rating kepuasan</div>
                <div class="value">{{ $num($r['average']) }}@if ($r['average'] !== null) <span class="sub">/ 5</span>@endif</div>
                <div class="sub">dari {{ $r['total'] }} rating</div>
            </div></td>
            <td></td>
        </tr>
    </table>

    <table class="split" style="margin-top: 4px;">
        <tr>
            <td>
                <h2>Layanan surat terbanyak</h2>
                <table>
                    <tr><th>Layanan</th><th class="n">Jumlah</th></tr>
                    @forelse ($l['top_services'] as $item)
                        <tr><td>{{ $item['name'] }}</td><td class="n">{{ $item['total'] }}</td></tr>
                    @empty
                        <tr><td colspan="2">Tidak ada surat dalam periode ini.</td></tr>
                    @endforelse
                </table>
            </td>
            <td>
                <h2>Kategori laporan terbanyak</h2>
                <table>
                    <tr><th>Kategori</th><th class="n">Jumlah</th></tr>
                    @forelse ($c['top_categories'] as $item)
                        <tr><td>{{ $item['name'] }}</td><td class="n">{{ $item['total'] }}</td></tr>
                    @empty
                        <tr><td colspan="2">Tidak ada laporan dalam periode ini.</td></tr>
                    @endforelse
                </table>
            </td>
        </tr>
    </table>

    <h2>Rekap per wilayah</h2>
    <table>
        <tr>
            <th>RW</th><th>RT</th>
            <th class="n">Surat masuk</th><th class="n">Selesai</th><th class="n">Berjalan</th>
            <th class="n">Laporan masuk</th><th class="n">Selesai</th><th class="n">Berjalan</th>
            <th>Kategori terbanyak</th><th class="n">Rating</th>
        </tr>
        @foreach ($regionRows as $item)
            @php($row = $item['row'])
            <tr @class(['total' => $item['total']])>
                <td>{{ $item['rw'] }}</td>
                <td>{{ $item['rt'] }}</td>
                <td class="n">{{ $row['letters']['total'] }}</td>
                <td class="n">{{ $row['letters']['completed'] }}</td>
                <td class="n">{{ $row['letters']['active'] }}</td>
                <td class="n">{{ $row['complaints']['total'] }}</td>
                <td class="n">{{ $row['complaints']['resolved'] }}</td>
                <td class="n">{{ $row['complaints']['active'] }}</td>
                <td>{{ $row['top_category'] ? "{$row['top_category']['name']} ({$row['top_category']['total']})" : '—' }}</td>
                <td class="n">{{ $num($row['ratings']['average']) }}{{ $row['ratings']['total'] ? " ({$row['ratings']['total']})" : '' }}</td>
            </tr>
        @endforeach
    </table>

    @foreach ([
        // Kolom teks panjang (layanan, pemohon, kategori, judul) boleh turun baris; sisanya tidak.
        ['Daftar surat pengantar', \App\Support\Stats\RecapExport::LETTER_HEADERS, $letters, 'surat', [3, 6]],
        ['Daftar laporan warga', \App\Support\Stats\RecapExport::COMPLAINT_HEADERS, $complaints, 'laporan', [3, 4]],
    ] as [$heading, $headers, $rows, $noun, $wrap])
        <h2 class="page-break">{{ $heading }} ({{ $rows->count() }})</h2>
        @if ($rows->count() > $limit)
            <p class="note">Menampilkan {{ $limit }} {{ $noun }} pertama dari {{ $rows->count() }}. Daftar lengkap ada di ekspor Excel.</p>
        @endif
        <table class="list">
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
            @forelse ($rows->take($limit) as $row)
                <tr>
                    @foreach ($row as $i => $value)
                        <td @class(['wrap' => in_array($i, $wrap, true)])>{{ $cell($value) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headers) }}">Tidak ada {{ $noun }} dalam periode ini.</td></tr>
            @endforelse
        </table>
    @endforeach
</body>
</html>
