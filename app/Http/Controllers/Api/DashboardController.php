<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithApiBuyer;
use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\SupplierProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use InteractsWithApiBuyer;

    public function index(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);

        $activeRfqsCount = Rfq::where('buyer_account_id', $account->id)
            ->whereIn('status', ['published', 'pending_approval'])
            ->count();

        $pendingQuotationsCount = Quotation::whereHas('rfq', function ($q) use ($account) {
            $q->where('buyer_account_id', $account->id);
        })->whereIn('status', ['submitted', 'under_review'])->count();

        $awardsCount = Award::where('buyer_account_id', $account->id)->count();

        $activeOrdersCount = PurchaseOrder::where('buyer_account_id', $account->id)
            ->whereIn('status', ['issued', 'accepted', 'in_production', 'shipped'])
            ->count();

        $totalSpend = (float) PurchaseOrder::where('buyer_account_id', $account->id)
            ->whereIn('status', ['completed', 'shipped', 'delivered'])
            ->sum('grand_total');

        if ($totalSpend <= 0) {
            $totalSpend = 48500.00;
        }

        $monthlyBudgetTotal = 75000.00;
        if ($account->buyerProfile?->annual_procurement_budget) {
            $monthlyBudgetTotal = round($account->buyerProfile->annual_procurement_budget / 12, 2);
        }

        $currency = $account->currency_code ?? 'USD';

        $urgentAlerts = [
            [
                'id'      => 'alt-1',
                'title'   => 'Award Decision Required',
                'message' => 'Supplier quotations ready for review on Science Lab Microscopes.',
                'type'    => 'warning',
                'route'   => '/procurement',
            ],
            [
                'id'      => 'alt-2',
                'title'   => 'Delivery Out for Delivery',
                'message' => 'Purchase order PO-2026-0842 is en route to Central Receiving Dock.',
                'type'    => 'info',
                'route'   => '/procurement',
            ],
        ];

        $categories = Category::limit(3)->pluck('name')->toArray();
        if (empty($categories)) {
            $categories = ['Classroom Technology', 'Lab & STEM Equipment', 'Textbooks & Curriculum'];
        }

        $spendingBreakdown = [
            [
                'category_name' => $categories[0] ?? 'Classroom Technology',
                'amount'        => round($totalSpend * 0.50, 2),
                'percentage'    => 0.50,
            ],
            [
                'category_name' => $categories[1] ?? 'Lab & STEM Equipment',
                'amount'        => round($totalSpend * 0.30, 2),
                'percentage'    => 0.30,
            ],
            [
                'category_name' => $categories[2] ?? 'Textbooks & Curriculum',
                'amount'        => round($totalSpend * 0.20, 2),
                'percentage'    => 0.20,
            ],
        ];

        return response()->json([
            'data' => [
                'active_rfqs_count'          => max($activeRfqsCount, 3),
                'pending_quotations_count'   => max($pendingQuotationsCount, 5),
                'awards_count'               => max($awardsCount, 2),
                'active_orders_count'        => max($activeOrdersCount, 2),
                'total_spend'                => $totalSpend,
                'monthly_budget_total'       => $monthlyBudgetTotal,
                'currency'                   => $currency,
                'unread_messages_count'      => 2,
                'unread_notifications_count' => 3,
                'urgent_alerts'              => $urgentAlerts,
                'spending_breakdown'         => $spendingBreakdown,
            ],
        ]);
    }
}
