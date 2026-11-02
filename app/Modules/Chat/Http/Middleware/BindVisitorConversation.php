<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Middleware;

use App\Models\Conversation;
use App\Modules\Chat\Application\Exceptions\ConversationRejected;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identitas pengunjung anonim (kartu 495): header X-Visitor-Token harus cocok dengan
 * HMAC yang tersimpan DAN belum kedaluwarsa. Tidak ada, salah, atau kedaluwarsa
 * dijawab SAMA -- membedakannya memberi tahu penebak token mana yang pernah sah.
 */
final class BindVisitorConversation
{
    public const string HEADER = 'X-Visitor-Token';

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->header(self::HEADER, '');
        $bound = $request->route('conversation');
        $conversation = $bound instanceof Conversation
            ? $bound
            : Conversation::query()->where('public_id', (string) $bound)->first();

        if ($token === ''
            || $conversation === null
            || $conversation->visitor_token_hash === null
            || ! hash_equals($conversation->visitor_token_hash, Conversation::visitorTokenHash($token))
            || $conversation->visitor_expires_at === null
            || ! $conversation->visitor_expires_at->isFuture()) {
            throw ConversationRejected::visitorExpired();
        }

        $request->route()?->setParameter('conversation', $conversation);

        return $next($request);
    }
}
