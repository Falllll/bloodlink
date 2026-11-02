<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Controllers;

use App\Models\Conversation;
use App\Models\Facility;
use App\Models\Message;
use App\Models\User;
use App\Modules\Chat\Application\AssignConversation;
use App\Modules\Chat\Application\CloseConversation;
use App\Modules\Chat\Application\EscalateConversation;
use App\Modules\Chat\Application\PostMessage;
use App\Modules\Chat\Application\StartConversation;
use App\Modules\Chat\Domain\MessageSenderKind;
use App\Modules\Chat\Http\Requests\ConversationListRequest;
use App\Modules\Chat\Http\Requests\EscalateConversationRequest;
use App\Modules\Chat\Http\Requests\MessageListRequest;
use App\Modules\Chat\Http\Requests\PostMessageRequest;
use App\Modules\Chat\Http\Requests\StartConversationRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Percakapan & eskalasi (kartu 495), dua sisi:
 *  - PENGUNJUNG anonim di /conversations, dikenali header X-Visitor-Token
 *    (BindVisitorConversation). Tokennya keluar SEKALI, di respons 201 store().
 *  - PETUGAS di /inbox/conversations, auth:sanctum + facility.context + policy.
 */
final class ConversationController
{
    use AppliesListQuery;

    public function store(StartConversationRequest $request, StartConversation $start, PostMessage $post): JsonResponse
    {
        $facilityId = $request->facilityPublicId() === null
            ? null
            : (int) Facility::query()->where('public_id', $request->facilityPublicId())->value('id');

        // 256 bit acak. Hanya HMAC-nya yang disimpan; kehilangan token = percakapan baru.
        $visitorToken = bin2hex(random_bytes(32));
        $conversation = $start->handle($facilityId, $visitorToken);
        $message = $post->handle($conversation, MessageSenderKind::VISITOR, $request->message(), null);

        return ApiResponse::success([
            'conversation' => $conversation->load('facility')->toApiArray(),
            'message' => $message->toApiArray(),
            // SATU-SATUNYA tempat token ini pernah muncul di respons mana pun.
            'visitor_token' => $visitorToken,
            'visitor_expires_at' => $conversation->visitor_expires_at?->toAtomString(),
        ])->setStatusCode(201);
    }

    public function visitorMessages(MessageListRequest $request, Conversation $conversation): JsonResponse
    {
        return $this->messagePage($request, $conversation);
    }

    public function visitorPostMessage(PostMessageRequest $request, Conversation $conversation, PostMessage $post): JsonResponse
    {
        $message = $post->handle($conversation, MessageSenderKind::VISITOR, $request->body(), null);

        return ApiResponse::success($message->toApiArray())->setStatusCode(201);
    }

    public function escalate(EscalateConversationRequest $request, Conversation $conversation, EscalateConversation $escalate): JsonResponse
    {
        $facilityId = (int) Facility::query()->where('public_id', $request->facilityPublicId())->value('id');

        return ApiResponse::success($escalate->handle($conversation, $request->reason(), $facilityId)->load('facility')->toApiArray());
    }

    /** Kotak masuk: hanya percakapan fasilitas petugas (scopeVisibleTo), terbaru dieskalasi dulu. */
    public function index(ConversationListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Conversation::class);

        $query = Conversation::query()
            ->visibleTo($this->user($request))
            ->whereNotNull('escalated_at')
            ->with(['facility', 'assignee']);

        /** @var CursorPaginator<int, Conversation> $page */
        $page = $this->listing($query, $request);

        return ApiResponse::paginated($page, transform: fn (Conversation $c): array => $c->toApiArray());
    }

    public function messages(MessageListRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        return $this->messagePage($request, $conversation);
    }

    public function postMessage(PostMessageRequest $request, Conversation $conversation, PostMessage $post): JsonResponse
    {
        Gate::authorize('respond', $conversation);

        $message = $post->handle($conversation, MessageSenderKind::STAFF, $request->body(), $this->user($request)->id);

        return ApiResponse::success($message->toApiArray())->setStatusCode(201);
    }

    public function assign(Request $request, Conversation $conversation, AssignConversation $assign): JsonResponse
    {
        Gate::authorize('assign', $conversation);

        return ApiResponse::success($assign->handle($conversation, $this->user($request)->id)->load(['facility', 'assignee'])->toApiArray());
    }

    public function close(Conversation $conversation, CloseConversation $close): JsonResponse
    {
        Gate::authorize('close', $conversation);

        return ApiResponse::success($close->handle($conversation)->load(['facility', 'assignee'])->toApiArray());
    }

    private function messagePage(MessageListRequest $request, Conversation $conversation): JsonResponse
    {
        /** @var CursorPaginator<int, Message> $page */
        $page = $this->listing(Message::query()->where('conversation_id', $conversation->id), $request);

        return ApiResponse::paginated($page, transform: fn (Message $m): array => $m->toApiArray());
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
