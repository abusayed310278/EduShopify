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

        $conversations = Conversation::with(['messages' => fn ($q) => $q->latest()->limit(1)])
            ->when($type && $type !== 'all', fn ($q) => $q->where('context_type', $type))
            ->when($search, fn ($q) => $q->where('title', 'like', "%{$search}%"))
            ->latest('updated_at')
            ->get();

        if ($conversations->isEmpty()) {
            // Seed a helpful sample conversation if none exists yet
            $conv = Conversation::create([
                'title'                 => 'Inquiry: Science Lab Microscopes Delivery',
                'context_type'          => 'rfq',
                'created_by_account_id' => $account->id,
                'created_by_user_id'    => $user->id,
            ]);

            Message::create([
                'conversation_id'   => $conv->id,
                'sender_account_id' => $account->id,
                'sender_user_id'    => $user->id,
                'body'              => 'Hello, can you confirm if optics kits can be shipped by December 15th?',
            ]);

            // Seed a counterpart reply so a fresh thread reads as a real two-sided
            // conversation. sender_account_id/sender_user_id are left null (no real
            // supplier account exists yet) which is enough to make is_me resolve to
            // false for this message.
            Message::create([
                'conversation_id'   => $conv->id,
                'sender_account_id' => null,
                'sender_user_id'    => null,
                'body'              => 'Thanks for reaching out, yes - we can ship the optics kits within your 14-day window. Let us know the quantity you need.',
            ]);

            $conversations = collect([$conv]);
        }

        $data = $conversations->map(function ($c) use ($user) {
            $lastMsg = $c->messages->first();
            return [
                'id'                => $c->id,
                'subject'           => $c->title ?? 'Procurement Discussion',
                'conversation_type' => $c->context_type ?? 'rfq',
                'peer_name'         => 'Global Science Supplies Ltd',
                'last_message'      => $lastMsg?->body ?? 'Tap to view discussion thread...',
                'updated_at'        => $c->updated_at ? $c->updated_at->diffForHumans() : 'Recently',
                'unread_count'      => 1,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->currentUser($request);

        $conversation = Conversation::find($id) ?? Conversation::first();

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $messages = Message::where('conversation_id', $conversation->id)
            ->oldest()
            ->get()
            ->map(function ($m) use ($user) {
                return [
                    'id'              => $m->id,
                    'conversation_id' => $m->conversation_id,
                    'sender_name'     => $m->sender_user_id === $user->id ? 'You' : 'Educational Supplier',
                    'sender_user_id'  => $m->sender_user_id,
                    'is_me'           => $m->sender_user_id === $user->id,
                    'is_system'       => false,
                    'body'            => $m->body,
                    'message_body'    => $m->body,
                    'created_at'      => $m->created_at ? $m->created_at->format('g:i A') : 'Just now',
                ];
            });

        return response()->json([
            'data' => [
                'id'                => $conversation->id,
                'subject'           => $conversation->title ?? 'Procurement Discussion',
                'conversation_type' => $conversation->context_type ?? 'rfq',
                'peer_name'         => 'Global Science Supplies Ltd',
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
