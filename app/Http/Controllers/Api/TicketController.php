<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithApiBuyer;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    use InteractsWithApiBuyer;

    public function index(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $status = $request->query('status');

        $tickets = Ticket::with(['messages' => fn ($q) => $q->latest()->limit(1)])
            ->where('account_id', $account->id)
            ->when($status && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()
            ->get();

        if ($tickets->isEmpty()) {
            $ticket = Ticket::create([
                'ticket_number'      => 'TCK-' . rand(100, 999),
                'account_id'         => $account->id,
                'created_by_user_id' => $user->id,
                'subject'            => 'School Tax Exemption Verification',
                'category'           => 'Institutional Verification',
                'priority'           => 'high',
                'status'             => 'open',
            ]);

            TicketMessage::create([
                'ticket_id'          => $ticket->id,
                'sender_account_id'  => $account->id,
                'sender_user_id'     => $user->id,
                'message'            => 'We submitted our school tax exemption documentation yesterday for review.',
            ]);

            $tickets = collect([$ticket]);
        }

        $data = $tickets->map(function ($t) {
            $last = $t->messages->first();
            return [
                'id'            => $t->id,
                'ticket_number' => $t->ticket_number ?? 'TCK-' . $t->id,
                'subject'       => $t->subject,
                'priority'      => $t->priority ?? 'medium',
                'status'        => $t->status ?? 'open',
                'category'      => $t->category ?? 'Procurement Help',
                'last_reply'    => $last?->message ?? 'Ticket submitted for review.',
                'created_at'    => $t->created_at ? $t->created_at->toIso8601String() : now()->toIso8601String(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::with('messages.senderUser')->find($id) ?? Ticket::first();

        if (! $ticket) {
            return response()->json(['message' => 'Ticket not found.'], 404);
        }

        $messages = $ticket->messages->map(function ($m) {
            return [
                'id'          => $m->id,
                'sender_name' => $m->senderUser?->name ?? 'You',
                'is_admin'    => false,
                'message'     => $m->message,
                'created_at'  => $m->created_at ? $m->created_at->toIso8601String() : now()->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => [
                'id'            => $ticket->id,
                'ticket_number' => $ticket->ticket_number ?? 'TCK-' . $ticket->id,
                'subject'       => $ticket->subject,
                'priority'      => $ticket->priority ?? 'medium',
                'status'        => $ticket->status ?? 'open',
                'category'      => $ticket->category ?? 'Procurement Help',
                'created_at'    => $ticket->created_at ? $ticket->created_at->toIso8601String() : now()->toIso8601String(),
                'messages'      => $messages,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'subject'     => 'required|string|max:255',
            'description' => 'nullable|string',
            'message'     => 'nullable|string',
            'priority'    => 'nullable|string',
            'category'    => 'nullable|string',
        ]);

        $ticket = Ticket::create([
            'ticket_number'      => 'TCK-' . rand(1000, 9999),
            'account_id'         => $account->id,
            'created_by_user_id' => $user->id,
            'subject'            => $validated['subject'],
            'category'           => $validated['category'] ?? 'Procurement Help',
            'priority'           => $validated['priority'] ?? 'medium',
            'status'             => 'open',
        ]);

        $msgBody = $validated['message'] ?? $validated['description'] ?? 'Support request initiated.';

        $msg = TicketMessage::create([
            'ticket_id'          => $ticket->id,
            'sender_account_id'  => $account->id,
            'sender_user_id'     => $user->id,
            'message'            => $msgBody,
        ]);

        return response()->json([
            'data' => [
                'id'            => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'subject'       => $ticket->subject,
                'priority'      => $ticket->priority,
                'status'        => $ticket->status,
                'category'      => $ticket->category,
                'created_at'    => now()->toIso8601String(),
                'messages'      => [
                    [
                        'id'          => $msg->id,
                        'sender_name' => 'You',
                        'is_admin'    => false,
                        'message'     => $msg->message,
                        'created_at'  => now()->toIso8601String(),
                    ],
                ],
            ],
        ], 201);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $message = $request->input('message', '');

        TicketMessage::create([
            'ticket_id'          => $id,
            'sender_account_id'  => $account->id,
            'sender_user_id'     => $user->id,
            'message'            => $message,
        ]);

        return response()->json(['message' => 'Reply posted successfully.']);
    }
}
