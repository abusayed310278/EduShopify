<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithApiBuyer;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    use InteractsWithApiBuyer;

    public function index(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);

        $query = Review::with([
            'supplierAccount.supplierProfile.country',
            'listing.mainCategory',
            'rfq',
            'reply.repliedBy',
            'quotation',
            'purchaseOrder',
        ])
        ->where('buyer_account_id', $account->id);

        if ($request->filled('type') && in_array($request->type, ['product', 'supplier'])) {
            $query->where('review_type', $request->type);
        }

        $reviews = $query->latest()->paginate($request->input('per_page', 20));

        $data = collect($reviews->items())->map(function (Review $r) use ($request) {
            $supplierProfile = $r->supplierAccount?->supplierProfile;
            $supplierName = $supplierProfile?->display_name ?? $supplierProfile?->company_name ?? $r->supplierAccount?->name ?? 'Educational Supplier';
            $supplierLogo = $supplierProfile?->logo ? asset('storage/' . ltrim($supplierProfile->logo, '/')) : null;

            $listingName = $r->listing?->name;
            $listingImage = $r->listing?->primary_image_url ?? $r->listing?->thumbnail_url;

            return [
                'id'                  => $r->id,
                'review_type'         => $r->review_type ?? ($r->listing_id ? 'product' : 'supplier'),
                'review_context'      => $r->review_context,
                'target_name'         => $r->review_type === 'product' ? ($listingName ?? 'Product') : $supplierName,
                'supplier_name'       => $supplierName,
                'supplier_logo'       => $supplierLogo,
                'listing_id'          => $r->listing_id,
                'listing_name'        => $listingName,
                'listing_image'       => $listingImage,
                'rfq_id'              => $r->rfq_id,
                'rfq_number'          => $r->rfq?->rfq_number,
                'quotation_id'        => $r->quotation_id,
                'purchase_order_id'   => $r->purchase_order_id,
                'rating'              => (int) $r->rating,
                'title'               => $r->title ?? '',
                'comment'             => $r->comment ?? '',
                'status'              => $r->status ?? 'published',
                'created_at'          => $r->created_at?->toIso8601String(),
                'created_at_human'    => $r->created_at?->format('M d, Y'),
                'reply'               => $r->reply ? [
                    'id'         => $r->reply->id,
                    'reply'      => $r->reply->reply,
                    'created_at' => $r->reply->created_at?->format('M d, Y'),
                ] : null,
                'can_edit'            => $r->review_type === 'product' || $r->status === 'pending',
            ];
        });

        // Summary counts
        $totalCount = Review::where('buyer_account_id', $account->id)->count();
        $productCount = Review::where('buyer_account_id', $account->id)->where('review_type', 'product')->count();
        $supplierCount = Review::where('buyer_account_id', $account->id)->where('review_type', 'supplier')->count();
        $avgRating = (float) (Review::where('buyer_account_id', $account->id)->avg('rating') ?? 0);

        return response()->json([
            'data'         => $data,
            'current_page' => $reviews->currentPage(),
            'last_page'    => $reviews->lastPage(),
            'total'        => $reviews->total(),
            'counts'       => [
                'all'            => $totalCount,
                'product'        => $productCount,
                'supplier'       => $supplierCount,
                'average_rating' => round($avgRating, 1),
            ],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $review = Review::where('buyer_account_id', $account->id)->findOrFail($id);

        $validated = $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'title'   => 'nullable|string|max:255',
            'comment' => 'nullable|string',
        ]);

        $review->update([
            'rating'  => $validated['rating'],
            'title'   => $validated['title'] ?? $review->title,
            'comment' => $validated['comment'] ?? $review->comment,
            'status'  => 'pending',
        ]);

        return response()->json([
            'message' => 'Review updated successfully and resubmitted for moderation.',
            'review'  => $review,
        ]);
    }

    public function storePurchaseOrderReview(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'title'   => 'nullable|string|max:255',
            'comment' => 'nullable|string',
        ]);

        $po = PurchaseOrder::find($id);

        $review = Review::create([
            'buyer_account_id'    => $account->id,
            'supplier_account_id' => $po?->supplier_account_id ?? 1,
            'created_by_user_id'  => $user->id,
            'purchase_order_id'   => $id,
            'review_context'      => 'purchase_experience',
            'review_type'         => 'supplier',
            'rating'              => $validated['rating'],
            'title'               => $validated['title'] ?? 'Purchase Order Review',
            'comment'             => $validated['comment'] ?? '',
            'status'              => 'published',
        ]);

        return response()->json([
            'message' => 'Review submitted successfully.',
            'review'  => $review,
        ]);
    }

    public function storeQuotationReview(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'title'   => 'nullable|string|max:255',
            'comment' => 'nullable|string',
        ]);

        $q = Quotation::find($id);

        $review = Review::create([
            'buyer_account_id'    => $account->id,
            'supplier_account_id' => $q?->supplier_account_id ?? 1,
            'created_by_user_id'  => $user->id,
            'quotation_id'        => $id,
            'rfq_id'              => $q?->rfq_id,
            'review_context'      => 'quotation_experience',
            'review_type'         => 'supplier',
            'rating'              => $validated['rating'],
            'title'               => $validated['title'] ?? 'Quotation Review',
            'comment'             => $validated['comment'] ?? '',
            'status'              => 'published',
        ]);

        return response()->json([
            'message' => 'Review submitted successfully.',
            'review'  => $review,
        ]);
    }
}
