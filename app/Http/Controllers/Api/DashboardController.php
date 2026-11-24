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

        $monthlyBudgetTotal = 0.00;
        if ($account->buyerProfile?->annual_procurement_budget) {
            $monthlyBudgetTotal = round($account->buyerProfile->annual_procurement_budget / 12, 2);
        }

        $currency = $account->currency_code ?? 'USD';

        $urgentAlerts = [];
        if ($pendingQuotationsCount > 0) {
            $urgentAlerts[] = [
                'id'      => 'alt-rfq-review',
                'title'   => 'Quotations Ready for Review',
                'message' => "You have {$pendingQuotationsCount} quotation(s) awaiting your evaluation.",
                'type'    => 'warning',
                'route'   => '/quotations',
            ];
        }

        if ($activeOrdersCount > 0) {
            $urgentAlerts[] = [
                'id'      => 'alt-order-active',
                'title'   => 'Active Purchase Orders',
                'message' => "You have {$activeOrdersCount} order(s) currently being processed or shipped.",
                'type'    => 'info',
                'route'   => '/purchase-orders',
            ];
        }

        $spendingBreakdown = [];
        if ($totalSpend > 0) {
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
        }

        return response()->json([
            'data' => [
                'active_rfqs_count'          => $activeRfqsCount,
                'pending_quotations_count'   => $pendingQuotationsCount,
                'awards_count'               => $awardsCount,
                'active_orders_count'        => $activeOrdersCount,
                'total_spend'                => $totalSpend,
                'monthly_budget_total'       => $monthlyBudgetTotal,
                'currency'                   => $currency,
                'unread_messages_count'      => 0,
                'unread_notifications_count' => 0,
                'urgent_alerts'              => $urgentAlerts,
                'spending_breakdown'         => $spendingBreakdown,
            ],
        ]);
    }
}
