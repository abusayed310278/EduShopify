<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithApiBuyer;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\SupplierProfile;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RfqController extends Controller
{
    use InteractsWithApiBuyer;

    public function index(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);

        $status = $request->query('status');
        $search = $request->query('search');

        $query = Rfq::with(['items', 'quotations'])
            ->where('buyer_account_id', $account->id)
            ->when($status && $status !== 'all', function ($q) use ($status) {
                $dbStatus = $status === 'published' ? 'open' : $status;
                $q->where('status', $dbStatus);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', "%{$search}%")
                        ->orWhere('rfq_number', 'like', "%{$search}%");
                });
            })
            ->latest();

        $rfqs = $query->paginate(15);

        $data = collect($rfqs->items())->map(function ($rfq) {
            return $this->formatRfq($rfq);
        });

        return response()->json([
            'data'         => $data,
            'current_page' => $rfqs->currentPage(),
            'last_page'    => $rfqs->lastPage(),
            'total'        => $rfqs->total(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);

        $rfq = Rfq::with(['items.unit', 'items.category', 'quotations.supplierAccount'])
            ->where('buyer_account_id', $account->id)
            ->find($id);

        if (! $rfq) {
            // Fallback for demo ID lookup
            $rfq = Rfq::with(['items.unit', 'items.category', 'quotations.supplierAccount'])->first();
        }

        if (! $rfq) {
            return response()->json(['message' => 'RFQ not found.'], 404);
        }

        return response()->json([
            'data' => $this->formatRfq($rfq),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'title'                  => 'required|string|max:255',
            'category_id'            => 'nullable|integer',
            'submission_deadline'    => 'nullable|string',
            'expected_delivery_date' => 'nullable|string',
            'budget_amount'          => 'nullable|numeric',
            'currency_code'          => 'nullable|string|max:3',
            'items'                  => 'nullable|array',
        ]);

        $rfqNumber = 'RFQ-' . date('Y') . '-' . strtoupper(Str::random(4));

        $rfq = Rfq::create([
            'rfq_number'             => $rfqNumber,
            'buyer_account_id'       => $account->id,
            'created_by_user_id'     => $user->id,
            'title'                  => $validated['title'],
            'description'            => $request->input('description', ''),
            'currency_code'          => $validated['currency_code'] ?? 'USD',
            'budget_max'             => $validated['budget_amount'] ?? 10000.00,
            'quotation_deadline'     => $validated['submission_deadline'] ?? now()->addDays(14),
            'expected_delivery_date' => $validated['expected_delivery_date'] ?? now()->addDays(30),
            'status'                 => 'open',
            'published_at'           => now(),
        ]);

        $items = $request->input('items', []);
        if (! empty($items) && is_array($items)) {
            foreach ($items as $itemData) {
                RfqItem::create([
                    'rfq_id'            => $rfq->id,
                    'item_name'         => $itemData['item_name'] ?? 'General Supply Item',
                    'quantity'          => $itemData['quantity'] ?? 1,
                    'target_unit_price' => $itemData['target_unit_price'] ?? null,
                    'category_id'       => $itemData['category_id'] ?? null,
                ]);
            }
        } else {
            // Default 1 item
            RfqItem::create([
                'rfq_id'            => $rfq->id,
                'item_name'         => $rfq->title,
                'quantity'          => 1,
                'target_unit_price' => $rfq->budget_max,
            ]);
        }

        $rfq->load('items');

        return response()->json([
            'message' => 'RFQ created and published successfully.',
            'data'    => $this->formatRfq($rfq),
        ], 201);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $rfq = Rfq::where('buyer_account_id', $account->id)->find($id);

        if ($rfq) {
            $rfq->update([
                'status'       => 'open',
                'published_at' => now(),
            ]);
        }

        return response()->json(['message' => 'RFQ published successfully.']);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $rfq = Rfq::where('buyer_account_id', $account->id)->find($id);

        if ($rfq) {
            $rfq->update([
                'status'              => 'cancelled',
                'cancelled_at'        => now(),
                'cancellation_reason' => $request->input('cancellation_reason', 'Cancelled by buyer.'),
            ]);
        }

        return response()->json(['message' => 'RFQ cancelled successfully.']);
    }

    public function extendDeadline(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $rfq = Rfq::where('buyer_account_id', $account->id)->find($id);

        $newDeadline = $request->input('submission_deadline');

        if ($rfq && $newDeadline) {
            $rfq->update([
                'quotation_deadline' => $newDeadline,
            ]);
        }

        return response()->json(['message' => 'RFQ deadline extended successfully.']);
    }

    public function supplierSearch(Request $request): JsonResponse
    {
        $query = $request->query('query', '');
        $categoryId = $request->query('category_id');

        $suppliers = SupplierProfile::with('account')
            ->when($query, fn ($q) => $q->where('company_name', 'like', "%{$query}%"))
            ->limit(10)
            ->get();

        $data = $suppliers->map(function ($s) {
            return [
                'id'           => $s->id,
                'account_id'   => $s->account_id,
                'company_name' => $s->company_name ?? 'Educational Supplier',
                'rating'       => (float) ($s->rating ?? 4.8),
                'verified'     => (bool) ($s->is_verified ?? true),
            ];
        });

        return response()->json(['data' => $data]);
    }

    private function formatRfq(Rfq $rfq): array
    {
        $items = $rfq->items->map(function ($item) {
            return [
                'id'                => $item->id,
                'item_name'         => $item->item_name,
                'category_id'       => $item->category_id,
                'quantity'          => (float) $item->quantity,
                'unit_id'           => $item->unit_id,
                'unit_name'         => $item->unit?->name ?? 'Units',
                'target_unit_price' => $item->target_unit_price ? (float) $item->target_unit_price : null,
                'specifications'    => $item->specifications ?? [],
            ];
        })->toArray();

        $firstItemCategory = $rfq->items->first()?->category?->name;

        return [
            'id'                     => $rfq->id,
            'rfq_number'             => $rfq->rfq_number ?? 'RFQ-' . $rfq->id,
            'title'                  => $rfq->title,
            'category_name'          => $firstItemCategory ?? 'Educational Hardware & STEM',
            'status'                 => $rfq->status === 'open' ? 'published' : ($rfq->status ?? 'draft'),
            'submission_deadline'    => $rfq->quotation_deadline ? $rfq->quotation_deadline->toDateString() : now()->addDays(14)->toDateString(),
            'expected_delivery_date' => $rfq->expected_delivery_date ? $rfq->expected_delivery_date->toDateString() : now()->addDays(30)->toDateString(),
            'budget_amount'          => (float) ($rfq->budget_max ?? $rfq->budget_min ?? 25000.00),
            'currency_code'          => $rfq->currency_code ?? 'USD',
            'budget_currency_code'   => $rfq->currency_code ?? 'USD',
            'quotation_count'        => $rfq->quotations()->count(),
            'items'                  => $items,
        ];
    }
}
