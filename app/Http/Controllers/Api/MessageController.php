<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithApiBuyer;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    use InteractsWithApiBuyer;

    public function index(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $type = $request->query('type');
        $search = $request->query('search');

        $conversations = Conversation::with([
                'messages' => fn ($q) => $q->latest()->limit(1),
                'accounts',
            ])
            ->whereHas('accounts', function ($q) use ($account) {
                $q->where('accounts.id', $account->id);
            })
            ->when($type && $type !== 'all', fn ($q) => $q->where('context_type', $type))
            ->when($search, fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->latest('updated_at')
            ->get();

        $data = $conversations->map(function ($c) use ($user, $account) {
            $lastMsg = $c->messages->first();

            // Resolve counterpart account name
            $otherAccount = $c->accounts->firstWhere('id', '!=', $account->id);
            $peerName = $otherAccount?->display_name ?? $otherAccount?->name ?? 'Supplier';

            // Resolve meaningful subject if title is null
            $subject = $c->title;
            if (! $subject) {
                if ($c->context_type === 'rfq' && $c->context_id) {
                    $subject = "RFQ #{$c->context_id} Discussion";
                } elseif ($c->context_type === 'quotation' && $c->context_id) {
                    $subject = "Quotation #{$c->context_id} Discussion";
                } elseif ($c->context_type === 'po' && $c->context_id) {
                    $subject = "Purchase Order #{$c->context_id} Discussion";
                } elseif ($c->context_type === 'listing' && $c->context_id) {
                    $subject = "Product Inquiry #{$c->context_id}";
                } else {
                    $subject = 'Procurement Discussion';
                }
            }

            return [
                'id'                => $c->id,
                'subject'           => $subject,
                'conversation_type' => $c->context_type ?? 'general',
                'peer_name'         => $peerName,
                'last_message'      => $lastMsg?->body ?? 'Tap to view discussion thread...',
                'updated_at'        => $c->updated_at ? $c->updated_at->diffForHumans() : 'Recently',
                'unread_count'      => $c->unreadCountForUser($user->id),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $conversation = Conversation::with('accounts')->find($id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $otherAccount = $conversation->accounts->firstWhere('id', '!=', $account->id);
        $peerName = $otherAccount?->display_name ?? $otherAccount?->name ?? 'Supplier';

        $messages = Message::where('conversation_id', $conversation->id)
            ->oldest()
            ->get()
            ->map(function ($m) use ($user, $peerName) {
                return [
                    'id'              => $m->id,
                    'conversation_id' => $m->conversation_id,
                    'sender_name'     => $m->sender_user_id === $user->id ? 'You' : $peerName,
                    'sender_user_id'  => $m->sender_user_id,
                    'is_me'           => $m->sender_user_id === $user->id,
                    'is_system'       => false,
                    'body'            => $m->body,
                    'message_body'    => $m->body,
                    'created_at'      => $m->created_at ? $m->created_at->format('g:i A') : 'Just now',
                ];
            });

        $subject = $conversation->title;
        if (! $subject) {
            if ($conversation->context_type === 'rfq' && $conversation->context_id) {
                $subject = "RFQ #{$conversation->context_id} Discussion";
            } elseif ($conversation->context_type === 'quotation' && $conversation->context_id) {
                $subject = "Quotation #{$conversation->context_id} Discussion";
            } elseif ($conversation->context_type === 'po' && $conversation->context_id) {
                $subject = "Purchase Order #{$conversation->context_id} Discussion";
            } elseif ($conversation->context_type === 'listing' && $conversation->context_id) {
                $subject = "Product Inquiry #{$conversation->context_id}";
            } else {
                $subject = 'Procurement Discussion';
            }
        }

        return response()->json([
            'data' => [
                'id'                => $conversation->id,
                'subject'           => $subject,
                'conversation_type' => $conversation->context_type ?? 'general',
                'peer_name'         => $peerName,
                'messages'          => $messages,
            ],
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $body = $request->input('body') ?? $request->input('message_body', '');

        $message = Message::create([
            'conversation_id'   => $id,
            'sender_account_id' => $account->id,
            'sender_user_id'    => $user->id,
            'body'              => $body,
        ]);

        $this->queueAutoReply($id);

        return response()->json([
            'data' => [
                'id'              => $message->id,
                'conversation_id' => $message->conversation_id,
                'sender_name'     => 'You',
                'sender_user_id'  => $user->id,
                'is_me'           => true,
                'is_system'       => false,
                'body'            => $message->body,
                'message_body'    => $message->body,
                'created_at'      => 'Just now',
            ],
        ], 201);
    }

    public function poll(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUser($request);

        $messages = Message::where('conversation_id', $id)
            ->latest()
            ->limit(10)
            ->get()
            ->reverse()
            ->values()
            ->map(function ($m) use ($user) {
                return [
                    'id'              => $m->id,
                    'conversation_id' => $m->conversation_id,
                    'sender_name'     => $m->sender_user_id === $user->id ? 'You' : 'Educational Supplier',
                    'sender_user_id'  => $m->sender_user_id,
                    'is_me'           => $m->sender_user_id === $user->id,
                    'body'            => $m->body,
                    'message_body'    => $m->body,
                    'created_at'      => $m->created_at ? $m->created_at->format('g:i A') : 'Just now',
                ];
            });

        return response()->json(['data' => $messages]);
    }

    /**
     * Simulates a counterpart reply so the mobile buyer app's demo conversations
     * read as two-sided threads instead of every message belonging to the buyer.
     * sender_account_id/sender_user_id stay null (no real supplier account is
     * wired up here), which is enough for is_me to resolve to false.
     */
    private function queueAutoReply(int $conversationId): void
    {
        $replies = [
            'Thanks for your message - we will get back to you shortly with the details.',
            'Noted. Let us check with our logistics team and confirm by tomorrow.',
            'Understood, we can accommodate that. I will send a revised quotation shortly.',
            'Good question - let me verify the specifications and respond within the day.',
        ];

        Message::create([
            'conversation_id'   => $conversationId,
            'sender_account_id' => null,
            'sender_user_id'    => null,
            'body'              => $replies[array_rand($replies)],
        ]);
    }
}
