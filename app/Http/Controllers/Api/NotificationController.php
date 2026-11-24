<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithApiBuyer;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    use InteractsWithApiBuyer;

    public function index(Request $request): JsonResponse
    {
        $notifications = [
            [
                'id'         => 'notif-1',
                'title'      => 'New Quotation Received',
                'body'       => 'Premier Science Instruments submitted a proposal for RFQ-2026-0041 ($21,250.00).',
                'type'       => 'quotation',
                'created_at' => now()->subMinutes(35)->toIso8601String(),
                'action_url' => '/quotations',
                'data'       => ['rfq_id' => 1],
            ],
            [
                'id'         => 'notif-2',
                'title'      => 'Purchase Order Dispatched',
                'body'       => 'PO-2026-0104 has been dispatched by EduTech Innovations Asia. Tracking #TRK-9812.',
                'type'       => 'po',
                'created_at' => now()->subHours(3)->toIso8601String(),
                'action_url' => '/purchase-orders',
                'data'       => ['po_id' => 104],
            ],
            [
                'id'         => 'notif-3',
                'title'      => 'RFQ Deadline Expiring Soon',
                'body'       => 'RFQ-2026-0038 for Interactive Smartboards expires in 24 hours. 3 quotations pending review.',
                'type'       => 'rfq',
                'created_at' => now()->subHours(14)->toIso8601String(),
                'action_url' => '/rfqs',
                'data'       => ['rfq_id' => 38],
            ],
            [
                'id'         => 'notif-4',
                'title'      => 'Quarterly Budget Alert',
                'body'       => 'Your institutional spend for Q4 has reached 82% of the designated $100,000 budget.',
                'type'       => 'system',
                'created_at' => now()->subDays(1)->toIso8601String(),
                'read_at'    => now()->subHours(10)->toIso8601String(),
                'action_url' => '/dashboard',
            ],
        ];

        return response()->json(['data' => $notifications]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
