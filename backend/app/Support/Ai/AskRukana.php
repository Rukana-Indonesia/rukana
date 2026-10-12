<?php

namespace App\Support\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Support\Stats\ServiceRecap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ask Rukana (PRD 7.3, UAT 11): pengurus bertanya dalam bahasa sehari-hari, AI
 * menjawab hanya dari tool AskTools. Scope wilayah selalu dari akun penanya
 * (AskTools), bukan dari isi pertanyaan.
 */
class AskRukana
{
    /** Putaran tool paling banyak untuk satu pertanyaan. */
    private const MAX_ROUNDS = 6;

    /** Jumlah pesan sebelumnya (tanya + jawab) yang ikut dikirim sebagai konteks. */
    private const HISTORY = 6;

    private const TOOL_LABELS = [
        'get_complaint_summary' => 'Data laporan',
        'get_letter_stats' => 'Data surat',
        'get_pending_items' => 'Antrean saat ini',
        'get_rating_summary' => 'Data rating',
        'get_village_summary' => 'Ringkasan desa',
    ];

    public function __construct(
        private readonly AiGateway $ai,
        private readonly AskTools $tools,
        private readonly ServiceRecap $recap,
    ) {}

    /**
     * @return array{conversation: AiConversation, question: AiMessage, answer: AiMessage}
     *
     * @throws AiUnavailable
     */
    public function ask(User $user, ?AiConversation $conversation, string $question): array
    {
        $scope = $user->scope();
        $isNew = $conversation === null;
        $conversation ??= AiConversation::create([
            'user_id' => $user->id,
            'scope_level' => $scope->level,
            'scope_id' => $scope->regionId(),
            'title' => Str::limit($question, 80),
        ]);

        $messages = [...$this->history($conversation), ['role' => 'user', 'content' => $question]];
        $definitions = $this->tools->definitions($scope);
        $used = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $answer = null;
        $model = null;

        try {
            for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
                $reply = $this->ai->send($this->system($user), $messages, [
                    'tools' => $definitions,
                    'effort' => 'low',
                    'max_tokens' => 8000,
                ]);
                $usage['input_tokens'] += $reply->inputTokens;
                $usage['output_tokens'] += $reply->outputTokens;
                $model = $reply->model;

                if (! $reply->wantsTools()) {
                    $answer = $reply->text;
                    break;
                }

                $results = [];
                foreach ($reply->toolUses as $call) {
                    [$result, $status] = $this->tools->run($call['name'], $call['input'], $user, $conversation);
                    $results[] = [
                        'type' => 'tool_result',
                        'toolUseID' => $call['id'],
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'isError' => $status !== 'ok',
                    ];
                    $used[] = array_filter([
                        'name' => $call['name'],
                        'label' => self::TOOL_LABELS[$call['name']] ?? $call['name'],
                        'status' => $status,
                        'region' => $result['wilayah'] ?? null,
                        'period' => $result['periode']['label'] ?? null,
                    ]);
                }

                $messages[] = ['role' => 'assistant', 'content' => $reply->assistantContent];
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (AiUnavailable $e) {
            if ($isNew) {
                $conversation->delete();
            }

            throw $e;
        }

        $answer = $answer !== null && $answer !== ''
            ? $answer
            : 'Maaf, saya belum bisa menjawab pertanyaan ini. Coba tanyakan dengan lebih sederhana, mis. satu jenis data dan satu periode.';

        [$questionMessage, $answerMessage] = DB::transaction(function () use ($conversation, $question, $answer, $used, $model, $usage) {
            $q = $conversation->messages()->create(['role' => 'user', 'content' => $question]);
            $a = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $answer,
                'tools' => $used ?: null,
                'model' => $model,
                ...$usage,
            ]);
            $conversation->touch();

            return [$q, $a];
        });

        AiLog::invoked('ask', [
            'model' => $model,
            'tools' => array_column($used, 'name'),
            'denied' => count(array_filter($used, fn (array $u) => $u['status'] === 'denied')),
            ...$usage,
        ], $conversation, $user);

        return ['conversation' => $conversation, 'question' => $questionMessage, 'answer' => $answerMessage];
    }

    /** Contoh pertanyaan sesuai wilayah penanya, untuk tombol cepat di UI. */
    public function examples(User $user): array
    {
        $label = $this->recap->scopeLabel($user->scope());

        return match ($user->scope()->level?->value) {
            'rt' => [
                "Berapa laporan yang belum selesai di {$label}?",
                'Kategori laporan apa yang paling banyak bulan ini?',
                'Ada berapa surat yang menunggu verifikasi saya?',
            ],
            'rw' => [
                "Berapa laporan yang belum selesai di {$label}?",
                'RT mana yang paling banyak laporan sampah bulan ini?',
                'Surat apa yang paling banyak diajukan bulan ini?',
            ],
            default => [
                'Berapa laporan yang belum selesai di setiap RW?',
                'RT mana yang paling banyak laporan sampah bulan ini?',
                'Surat apa yang paling banyak diajukan bulan ini?',
                'Berapa rata-rata rating kepuasan bulan ini?',
            ],
        };
    }

    /** Tanya-jawab terakhir sebagai teks (tanpa jejak tool), dimulai dari pertanyaan. */
    private function history(AiConversation $conversation): array
    {
        $recent = $conversation->messages()->reorder('id', 'desc')->limit(self::HISTORY)->get()->reverse()->values();

        return $recent
            ->skipUntil(fn (AiMessage $m) => $m->role === 'user')
            ->map(fn (AiMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }

    private function system(User $user): string
    {
        $scope = $user->scope();
        $label = $this->recap->scopeLabel($scope);
        $today = CarbonImmutable::now(config('app.display_timezone'))->locale('id');
        $date = $today->translatedFormat('l, j F Y');
        $iso = $today->toDateString();

        return <<<PROMPT
        Anda adalah Ask Rukana, asisten data untuk pengurus RT/RW dan perangkat desa di
        aplikasi layanan warga Rukana. Hari ini {$date} ({$iso}, WIB).

        Penanya: {$user->role->label()}. Wilayah datanya: {$label}. Semua tool otomatis dibatasi
        ke wilayah ini oleh sistem. Bila penanya meminta data wilayah lain dan tool menolaknya,
        katakan dengan sopan bahwa Anda hanya bisa menjawab untuk {$label}, lalu tawarkan data
        {$label} bila relevan. Jangan pernah menebak angka wilayah lain.

        Cara menjawab:
        - Ambil angka hanya dari hasil tool. Jangan mengarang atau memperkirakan angka.
          Boleh membandingkan atau mengurutkan angka yang ada di hasil tool.
        - Sebutkan wilayah dan periode yang dipakai (mis. "Oktober 2026"). Bila penanya tidak
          menyebut periode, pakai bulan ini dan katakan begitu. "Bulan September" berarti
          September tahun ini bila sudah lewat, selain itu tahun lalu.
        - Jawab singkat dalam bahasa Indonesia sehari-hari: 1–4 kalimat, atau daftar pendek bila
          perlu. Tanpa tabel, tanpa istilah teknis seperti nama tool atau JSON.
        - Data yang tersedia hanya surat pengantar, laporan warga, rating, dan antrean. Data
          pribadi (nama pelapor, no. HP, NIK, alamat) tidak tersedia dan tidak boleh diminta.
          Untuk pertanyaan di luar itu, katakan Anda tidak punya datanya.
        - Anda hanya memberi informasi. Anda tidak bisa memverifikasi, meneruskan, menolak,
          atau menutup surat dan laporan; arahkan ke menu terkait bila diminta.
        PROMPT;
    }
}
