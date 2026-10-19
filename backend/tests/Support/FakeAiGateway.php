<?php

namespace Tests\Support;

use App\Support\Ai\AiGateway;
use App\Support\Ai\AiReply;
use App\Support\Ai\AiUnavailable;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * AiGateway tiruan untuk tes: balasan diantrekan lebih dulu, dan setiap permintaan
 * disimpan supaya tes bisa memeriksa apa yang dikirim ke model (mis. tidak ada nama
 * pelapor). Tidak pernah memanggil jaringan.
 */
class FakeAiGateway implements AiGateway
{
    /** @var list<array{system: string, messages: array, options: array}> */
    public array $requests = [];

    /** @var list<AiReply|AiUnavailable|Closure> */
    private array $queue = [];

    public function __construct(private bool $configured = true) {}

    public function configured(): bool
    {
        return $this->configured;
    }

    /** Balasan JSON (structured output). */
    public function json(array $data): self
    {
        return $this->reply(new AiReply('end_turn', json_encode($data), [], [['type' => 'text', 'text' => json_encode($data)]], 'claude-haiku-5-5', 100, 50));
    }

    public function text(string $text): self
    {
        return $this->reply(new AiReply('end_turn', $text, [], [['type' => 'text', 'text' => $text]], 'claude-haiku-5-5', 100, 50));
    }

    /** Satu atau beberapa panggilan tool sekaligus: [[name, input], ...]. */
    public function tools(array $calls): self
    {
        $uses = array_map(fn (array $c, int $i) => ['id' => 'toolu_'.count($this->queue).'_'.$i, 'name' => $c[0], 'input' => $c[1]], $calls, array_keys($calls));

        return $this->reply(new AiReply('tool_use', '', $uses, array_map(fn ($u) => ['type' => 'tool_use', ...$u], $uses), 'claude-haiku-5-5', 100, 20));
    }

    public function fail(string $message = 'Layanan AI sedang tidak bisa dihubungi.'): self
    {
        return $this->reply(new AiUnavailable($message));
    }

    public function reply(AiReply|AiUnavailable|Closure $reply): self
    {
        $this->queue[] = $reply;

        return $this;
    }

    public function send(string $system, array $messages, array $options = []): AiReply
    {
        if (! $this->configured) {
            throw AiUnavailable::notConfigured();
        }

        $this->requests[] = compact('system', 'messages', 'options');
        $next = array_shift($this->queue) ?? Assert::fail('FakeAiGateway: tidak ada balasan yang diantrekan.');
        $next = $next instanceof Closure ? $next($system, $messages, $options) : $next;

        if ($next instanceof AiUnavailable) {
            throw $next;
        }

        return $next;
    }

    /** Semua teks yang dikirim ke model (system + pesan + hasil tool), untuk cek kebocoran data. */
    public function sentText(): string
    {
        return json_encode($this->requests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
