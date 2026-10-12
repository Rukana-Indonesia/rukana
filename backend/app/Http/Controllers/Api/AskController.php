<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Support\Ai\AiGateway;
use App\Support\Ai\AiUnavailable;
use App\Support\Ai\AskRukana;
use App\Support\Stats\ServiceRecap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ask Rukana untuk RT, RW, operator, dan kepala desa (PRD 9). Percakapan hanya bisa
 * dibuka pemiliknya; jawaban selalu dibatasi wilayah akun (lihat AskTools).
 */
class AskController extends Controller
{
    public function index(Request $request, AskRukana $ask, AiGateway $ai, ServiceRecap $recap): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => AiConversation::where('user_id', $user->id)
                ->latest('updated_at')
                ->limit(30)
                ->get(['id', 'title', 'created_at', 'updated_at'])
                ->map(fn (AiConversation $c) => $this->conversation($c)),
            'meta' => [
                'scope_label' => $recap->scopeLabel($user->scope()),
                'examples' => $ask->examples($user),
                'ai_configured' => $ai->configured(),
            ],
        ]);
    }

    public function show(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);

        return response()->json(['data' => [
            ...$this->conversation($conversation),
            'messages' => $conversation->messages->map(fn (AiMessage $m) => $this->message($m)),
        ]]);
    }

    public function store(Request $request, AskRukana $ask): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
            'conversation_id' => ['nullable', 'integer'],
        ], ['question.required' => 'Tulis pertanyaan Anda dulu.']);

        $conversation = null;
        if (isset($data['conversation_id'])) {
            $conversation = AiConversation::find($data['conversation_id']) ?? abort(404, 'Data tidak ditemukan.');
            $this->authorizeOwner($request, $conversation);
        }

        // Satu pertanyaan bisa butuh beberapa putaran model + tool; batas bawaan PHP 30 detik terlalu ketat.
        set_time_limit(180);

        try {
            $result = $ask->ask($request->user(), $conversation, trim($data['question']));
        } catch (AiUnavailable $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ai_unavailable'], 503);
        }

        return response()->json(['data' => [
            'conversation' => $this->conversation($result['conversation']->refresh()),
            'messages' => [$this->message($result['question']), $this->message($result['answer'])],
        ]], 201);
    }

    public function destroy(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->authorizeOwner($request, $conversation);
        $conversation->delete();

        return response()->json(['message' => 'Percakapan dihapus.']);
    }

    private function authorizeOwner(Request $request, AiConversation $conversation): void
    {
        if ($conversation->user_id !== $request->user()->id) {
            throw new AuthorizationException('Anda tidak punya akses ke data ini.');
        }
    }

    private function conversation(AiConversation $c): array
    {
        return [
            'id' => $c->id,
            'title' => $c->title,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    private function message(AiMessage $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'tools' => $m->tools ?? [],
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
