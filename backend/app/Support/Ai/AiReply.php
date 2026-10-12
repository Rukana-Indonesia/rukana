<?php

namespace App\Support\Ai;

/**
 * Satu balasan model, sudah dinormalkan dari respons SDK.
 *
 * $assistantContent dikirim balik apa adanya sebagai giliran assistant di loop
 * tool (termasuk blok thinking), jadi jangan diubah.
 */
final readonly class AiReply
{
    /**
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolUses
     */
    public function __construct(
        public string $stopReason,
        public string $text,
        public array $toolUses,
        public mixed $assistantContent,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    public function wantsTools(): bool
    {
        return $this->stopReason === 'tool_use' && $this->toolUses !== [];
    }

    /** Isi JSON dari structured output; null bila bukan JSON objek. */
    public function json(): ?array
    {
        $data = json_decode($this->text, true);

        return is_array($data) ? $data : null;
    }
}
