<?php

namespace App\Support\Ai;

use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Illuminate\Support\Facades\Log;

/**
 * AiGateway lewat SDK resmi Anthropic. Thinking memakai bawaan model (adaptive);
 * kedalamannya diatur lewat effort. Pada model yang mendukungnya (Opus, Sonnet,
 * Fable), penolakan model dialihkan server ke model pengganti (fallbacks
 * "default"). Haiku tidak punya fallback; penolakan langsung dianggap gagal.
 */
class AnthropicGateway implements AiGateway
{
    private ?Client $client = null;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly float $timeout,
    ) {}

    public function configured(): bool
    {
        return (string) $this->apiKey !== '';
    }

    public function send(string $system, array $messages, array $options = []): AiReply
    {
        if (! $this->configured()) {
            throw AiUnavailable::notConfigured();
        }

        $outputConfig = ['effort' => $options['effort'] ?? 'medium'];
        if (isset($options['schema'])) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $options['schema']];
        }

        $fallback = $this->supportsFallback();

        try {
            $message = $this->client()->beta->messages->create(
                maxTokens: $options['max_tokens'] ?? 16000,
                messages: $messages,
                model: $this->model,
                fallbacks: $fallback ? 'default' : null,
                outputConfig: $outputConfig,
                system: $system,
                tools: $options['tools'] ?? null,
                betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
                requestOptions: ['timeout' => $this->timeout],
            );
        } catch (APIException $e) {
            Log::warning('Panggilan Claude gagal', ['error' => $e->getMessage()]);

            throw new AiUnavailable('Layanan AI sedang tidak bisa dihubungi. Coba lagi beberapa saat lagi.', previous: $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new AiUnavailable('AI tidak bisa memproses permintaan ini.');
        }

        $text = '';
        $toolUses = [];
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            } elseif ($block instanceof BetaToolUseBlock) {
                $toolUses[] = ['id' => $block->id, 'name' => $block->name, 'input' => $block->input];
            }
        }

        return new AiReply(
            stopReason: (string) $message->stopReason,
            text: trim($text),
            toolUses: $toolUses,
            assistantContent: $message->content,
            model: $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
        );
    }

    /** Fallback server-side hanya diterima model Opus, Sonnet, dan Fable; Haiku menolaknya (400). */
    public function supportsFallback(): bool
    {
        return preg_match('/^claude-(opus|sonnet|fable)-/', $this->model) === 1;
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: $this->apiKey);
    }
}
