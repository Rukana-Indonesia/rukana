<?php

namespace App\Support\Ai;

/**
 * Satu-satunya jalan ke LLM. Fitur AI hanya mengirim data agregat atau isi laporan
 * tanpa identitas pelapor (PRD 8); model tidak pernah menyentuh database.
 */
interface AiGateway
{
    public function configured(): bool;

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @param  array{
     *     tools?: list<array<string, mixed>>,
     *     schema?: array<string, mixed>,
     *     effort?: 'low'|'medium'|'high',
     *     max_tokens?: int,
     * }  $options
     *
     * @throws AiUnavailable
     */
    public function send(string $system, array $messages, array $options = []): AiReply;
}
