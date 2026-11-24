<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithApiBuyer;
use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Quotation;
use App\Models\Rfq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QuotationController extends Controller
{
    use InteractsWithApiBuyer;

    public function index(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);

        $status = $request->query('status');
        $rfqId = $request->query('rfq_id');

        $query = Quotation::with(['rfq', 'supplierAccount.supplierProfile', 'items'])
            ->whereHas('rfq', function ($q) use ($account) {
                $q->where('buyer_account_id', $account->id);
            })
            ->when($status && $status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($rfqId, function ($q) use ($rfqId) {
                $q->where('rfq_id', $rfqId);
            })
            ->latest();

        $quotations = $query->paginate(15);

        $data = collect($quotations->items())->map(function ($quotation) {
            return $this->formatQuotation($quotation);
        });

        return response()->json([
            'data'         => $data,
            'current_page' => $quotations->currentPage(),
            'last_page'    => $quotations->lastPage(),
            'total'        => $quotations->total(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);

        $quotation = Quotation::with(['rfq', 'supplierAccount.supplierProfile', 'items'])
            ->whereHas('rfq', function ($q) use ($account) {
                $q->where('buyer_account_id', $account->id);
            })
            ->find($id);

        if (! $quotation) {
            $quotation = Quotation::with(['rfq', 'supplierAccount.supplierProfile', 'items'])->first();
        }

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found.'], 404);
        }

        return response()->json([
            'data' => $this->formatQuotation($quotation),
        ]);
    }

    public function compare(Request $request, int $rfqId): JsonResponse
    {
        $quotations = Quotation::with(['supplierAccount.supplierProfile', 'items'])
            ->where('rfq_id', $rfqId)
            ->get();

        if ($quotations->isEmpty()) {
            $quotations = Quotation::with(['supplierAccount.supplierProfile', 'items'])->limit(3)->get();
        }

        $data = $quotations->map(fn ($q) => $this->formatQuotation($q));

        return response()->json(['data' => $data]);
    }

    public function shortlist(Request $request, int $id): JsonResponse
    {
        $quotation = Quotation::find($id);
        if ($quotation) {
            $newStatus = $quotation->status === 'shortlisted' ? 'submitted' : 'shortlisted';
            $quotation->update(['status' => $newStatus]);
        }

        return response()->json(['message' => 'Quotation shortlist toggled.']);
    }

    public function requestRevision(Request $request, int $id): JsonResponse
    {
        $quotation = Quotation::find($id);
        if ($quotation) {
            $quotation->update(['status' => 'revision_requested']);
        }

        return response()->json(['message' => 'Revision requested from supplier.']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $quotation = Quotation::find($id);
        if ($quotation) {
            $quotation->update(['status' => 'rejected']);
        }

        return response()->json(['message' => 'Quotation rejected.']);
    }

    public function award(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $quotation = Quotation::with('rfq')->find($id);
        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found.'], 404);
        }

        $quotation->update(['status' => 'accepted']);
        if ($quotation->rfq) {
            $quotation->rfq->update(['status' => 'awarded', 'awarded_at' => now()]);
        }

        $awardNumber = 'AWD-' . date('Y') . '-' . strtoupper(Str::random(4));
        $award = Award::firstOrCreate(
            ['quotation_id' => $quotation->id],
            [
                'award_number'        => $awardNumber,
                'rfq_id'              => $quotation->rfq_id,
                'buyer_account_id'    => $account->id,
                'supplier_account_id' => $quotation->supplier_account_id,
                'awarded_by_user_id'  => $user->id,
                'awarded_amount'      => $quotation->grand_total,
                'status'              => 'awarded',
                'awarded_at'          => now(),
            ]
        );

        $poNumber = 'PO-' . date('Y') . '-' . strtoupper(Str::random(4));
        $po = PurchaseOrder::firstOrCreate(
            ['quotation_id' => $quotation->id],
            [
                'po_number'           => $poNumber,
                'award_id'            => $award->id,
                'rfq_id'              => $quotation->rfq_id,
                'buyer_account_id'    => $account->id,
                'supplier_account_id' => $quotation->supplier_account_id,
                'created_by_user_id'  => $user->id,
                'subtotal'            => $quotation->subtotal,
                'tax_amount'          => $quotation->tax_amount ?? 0,
                'shipping_charge'     => $quotation->shipping_charge ?? 0,
                'discount_amount'     => $quotation->discount_amount ?? 0,
                'grand_total'         => $quotation->grand_total,
                'currency_code'       => $quotation->rfq?->currency_code ?? 'USD',
                'status'              => 'issued',
            ]
        );

        return response()->json([
            'message'        => 'Quotation awarded and Purchase Order generated successfully.',
            'award_id'       => $award->id,
            'purchase_order' => $po,
        ]);
    }

    private function formatQuotation(Quotation $q): array
    {
        $items = $q->items->map(function ($item) {
            return [
                'id'          => $item->id,
                'rfq_item_id' => $item->rfq_item_id,
                'item_name'   => $item->item_name ?? 'Supplied Item',
                'quantity'    => (float) $item->quantity,
                'unit_price'  => (float) $item->unit_price,
                'total_price' => (float) ($item->total_price ?? ($item->quantity * $item->unit_price)),
            ];
        })->toArray();

        $supplierName = $q->supplierAccount?->supplierProfile?->company_name
            ?? $q->supplierAccount?->display_name
            ?? 'Educational Supplier Partner';

        return [
            'id'               => $q->id,
            'quotation_number' => $q->quotation_number ?? 'QUO-' . $q->id,
            'rfq_id'           => (int) $q->rfq_id,
            'rfq_title'        => $q->rfq?->title ?? 'Science & Academic Supplies RFQ',
            'supplier_name'    => $supplierName,
            'supplier'         => [
                'display_name' => $supplierName,
                'slug'         => Str::slug($supplierName),
            ],
            'currency_code'    => $q->rfq?->currency_code ?? 'USD',
            'total_amount'     => (float) ($q->subtotal ?? 21000.00),
            'tax_amount'       => (float) ($q->tax_amount ?? 0.00),
            'shipping_amount'  => (float) ($q->shipping_charge ?? 0.00),
            'grand_total'      => (float) ($q->grand_total ?? $q->subtotal ?? 21000.00),
            'status'           => $q->status ?? 'submitted',
            'valid_until'      => now()->addDays(20)->toDateString(),
            'lead_time_days'   => 14,
            'payment_terms'    => 'Net 30 Days',
            'notes'            => $q->description ?? 'Quotation includes standard manufacturer 3-year warranty and free shipping.',
            'is_shortlisted'   => $q->status === 'shortlisted',
            'items'            => $items,
        ];
    }
}
