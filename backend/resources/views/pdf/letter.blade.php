@php
    $tz = config('app.display_timezone');
    $village = $letter->village;
    $applicant = $letter->user;
    $region = $letter->rt->label().' / '.$letter->rw->label();
    $withRw = $letter->flow->includes(\App\Enums\Level::Rw);
    $date = fn ($at) => $at?->timezone($tz)->translatedFormat('j F Y');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Surat Pengantar {{ $letter->code }}</title>
    <style>
        @page { margin: 2cm 2.2cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; line-height: 1.5; color: #111; }
        .kop { text-align: center; border-bottom: 3px double #111; padding-bottom: 8px; }
        .kop h1 { font-size: 14pt; margin: 0; letter-spacing: 1px; }
        .kop p { margin: 2px 0 0; font-size: 10pt; }
        .title { text-align: center; margin: 22px 0 18px; }
        .title h2 { font-size: 13pt; margin: 0; text-decoration: underline; letter-spacing: 1px; }
        .title p { margin: 2px 0 0; }
        table.data { margin: 8px 0 12px 24px; border-collapse: collapse; }
        table.data td { padding: 2px 0; vertical-align: top; }
        table.data td.label { width: 150px; }
        table.data td.sep { width: 16px; }
        table.sign { width: 100%; margin-top: 28px; border-collapse: collapse; }
        table.sign td { width: 50%; text-align: center; vertical-align: top; }
        .space { height: 70px; }
        .name { font-weight: bold; text-decoration: underline; }
        .muted { color: #555; font-size: 8.5pt; }
        .footer { position: fixed; bottom: -1.2cm; left: 0; right: 0; font-size: 8pt; color: #555; border-top: 1px solid #ccc; padding-top: 4px; }
    </style>
</head>
<body>
    <div class="kop">
        <h1>KETUA {{ strtoupper($region) }}</h1>
        <p>Desa {{ $village->name }}, Kecamatan {{ $village->district }}, {{ $village->regency }}</p>
        @if ($letter->rw->name)
            <p>{{ $letter->rw->name }}</p>
        @endif
    </div>

    <div class="title">
        <h2>SURAT PENGANTAR</h2>
        <p>Nomor: {{ $letter->code }}</p>
    </div>

    <p>Yang bertanda tangan di bawah ini, Ketua {{ $region }} Desa {{ $village->name }}, menerangkan bahwa:</p>

    <table class="data">
        <tr><td class="label">Nama</td><td class="sep">:</td><td>{{ $applicant->name }}</td></tr>
        @if ($letter->nik_masked)
            <tr><td class="label">NIK</td><td class="sep">:</td><td>{{ $letter->nik_masked }}</td></tr>
        @endif
        <tr><td class="label">Alamat</td><td class="sep">:</td><td>{{ $region }}, Desa {{ $village->name }}</td></tr>
        @if ($applicant->residency_status)
            <tr><td class="label">Status tinggal</td><td class="sep">:</td><td>{{ $applicant->residency_status->label() }}</td></tr>
        @endif
    </table>

    <p>adalah benar warga kami yang berdomisili di alamat tersebut. Surat pengantar ini diberikan untuk mengurus:</p>

    <table class="data">
        <tr><td class="label">Layanan</td><td class="sep">:</td><td>{{ $letter->serviceType->name }}</td></tr>
        <tr><td class="label">Keperluan</td><td class="sep">:</td><td>{{ $letter->purpose }}</td></tr>
        @foreach ($letter->form_data ?? [] as $field)
            <tr><td class="label">{{ $field['label'] }}</td><td class="sep">:</td><td>{{ $field['value'] }}</td></tr>
        @endforeach
    </table>

    <p>Demikian surat pengantar ini dibuat untuk dipergunakan sebagaimana mestinya.</p>

    <table class="sign">
        <tr>
            <td>
                @if ($withRw)
                    <br>Mengetahui,<br>Ketua {{ $letter->rw->label() }}
                    <div class="space"></div>
                    <div class="name">{{ $approved?->actor?->name ?? '..............................' }}</div>
                    @if ($approved)
                        <div class="muted">Disetujui di Rukana, {{ $date($approved->created_at) }}</div>
                    @endif
                @endif
            </td>
            <td>
                {{ $village->name }}, {{ $date($verified?->created_at ?? now()) }}<br>
                <br>Ketua {{ $letter->rt->label() }}
                <div class="space"></div>
                <div class="name">{{ $verified?->actor?->name ?? '..............................' }}</div>
                @if ($verified)
                    <div class="muted">Diverifikasi di Rukana, {{ $date($verified->created_at) }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="footer">
        Dibuat melalui Rukana. Cek status surat ini dengan kode {{ $letter->code }} di {{ $checkUrl }}
    </div>
</body>
</html>
