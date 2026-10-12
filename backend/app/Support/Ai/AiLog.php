<?php

namespace App\Support\Ai;

use App\Models\AiConversation;
use App\Models\AiToolCall;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Jejak setiap pemanggilan AI dan tool-nya (PRD 8, UAT 11). Argumen selalu memuat
 * scope yang disisipkan backend; hasil hanya angka agregat, tanpa data pribadi.
 */
class AiLog
{
    public static function tool(
        string $feature,
        string $tool,
        array $arguments,
        ?array $result,
        string $status,
        float $startedAt,
        ?User $user = null,
        ?AiConversation $conversation = null,
        ?Model $subject = null,
    ): AiToolCall {
        return AiToolCall::create([
            'feature' => $feature,
            'user_id' => $user?->id,
            'ai_conversation_id' => $conversation?->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'tool_name' => $tool,
            'arguments' => $arguments,
            'result' => $result,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /** Satu pemanggilan model, dicatat di audit log. */
    public static function invoked(string $feature, array $metadata = [], ?Model $subject = null, ?User $user = null): void
    {
        Audit::log('ai.invoked', $subject, ['feature' => $feature, ...$metadata], $user);
    }
}
