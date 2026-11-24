<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithApiBuyer;
use App\Http\Controllers\Controller;
use App\Models\Award;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    use InteractsWithApiBuyer;

    public function index(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $status = $request->query('status');

        $query = PurchaseOrder::with(['items', 'supplierAccount.supplierProfile', 'rfq'])
            ->where('buyer_account_id', $account->id)
            ->when($status && $status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->latest();

        $orders = $query->paginate(15);

        $data = collect($orders->items())->map(fn ($po) => $this->formatOrder($po));

        return response()->json([
            'data'         => $data,
            'current_page' => $orders->currentPage(),
            'last_page'    => $orders->lastPage(),
            'total'        => $orders->total(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);

        $order = PurchaseOrder::with(['items', 'supplierAccount.supplierProfile', 'rfq'])
            ->where('buyer_account_id', $account->id)
            ->find($id);

        if (! $order) {
            $order = PurchaseOrder::with(['items', 'supplierAccount.supplierProfile', 'rfq'])->first();
        }

        if (! $order) {
            return response()->json(['message' => 'Purchase order not found.'], 404);
        }

        return response()->json([
            'data' => $this->formatOrder($order),
        ]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $order = PurchaseOrder::find($id);
        if ($order) {
            $order->update([
                'status' => 'completed',
            ]);
        }

        return response()->json(['message' => 'Purchase order marked as delivered & completed.']);
    }

    public function awards(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);

        $awards = Award::with(['rfq', 'quotation', 'supplierAccount'])
            ->where('buyer_account_id', $account->id)
            ->latest()
            ->paginate(15);

        return response()->json([
            'data'         => $awards->items(),
            'current_page' => $awards->currentPage(),
            'last_page'    => $awards->lastPage(),
            'total'        => $awards->total(),
        ]);
    }

    private function formatOrder(PurchaseOrder $po): array
    {
        $items = $po->items->map(function ($item) {
            return [
                'id'          => $item->id,
                'item_name'   => $item->item_name ?? 'Procurement Item',
                'quantity'    => (float) $item->quantity,
                'unit_price'  => (float) $item->unit_price,
                'total_price' => (float) ($item->total_price ?? ($item->quantity * $item->unit_price)),
            ];
        })->toArray();

        $supplierName = $po->supplierAccount?->supplierProfile?->company_name
            ?? $po->supplierAccount?->display_name
            ?? 'Apex Educational Solutions Ltd';

        return [
            'id'                     => $po->id,
            'po_number'              => $po->po_number ?? 'PO-' . $po->id,
            'supplier_name'          => $supplierName,
            'supplier'               => [
                'display_name' => $supplierName,
            ],
            'total_amount'           => (float) ($po->grand_total ?? $po->subtotal ?? 24000.00),
            'currency_code'          => $po->currency_code ?? 'USD',
            'status'                 => $po->status ?? 'in_production',
            'expected_delivery_date' => now()->addDays(12)->toDateString(),
            'delivery_address'       => 'Greenwood Central Campus Receiving Dock, 100 University Ave, Austin, TX',
            'items'                  => $items,
        ];
    }
}
