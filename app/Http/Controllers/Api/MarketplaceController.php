<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithApiBuyer;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountLocation;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\ContactInquiry;
use App\Models\Listing;
use App\Models\Quotation;
use App\Models\Review;
use App\Models\SavedItem;
use App\Models\SupplierProfile;
use App\Models\Rfq;
use App\Services\Account\PublicSupplierQuery;
use App\Services\Catalog\PublicListingQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketplaceController extends Controller
{
    use InteractsWithApiBuyer;

    public function products(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $search = $request->query('q') ?? $request->query('search');
        $category = $request->query('category') ?? $request->query('category_id');
        $minPrice = $request->query('min_price');
        $maxPrice = $request->query('max_price');
        $sort = $request->query('sort');

        $query = Listing::products()
            ->with(['mainCategory', 'supplierAccount.supplierProfile', 'media', 'variants'])
            ->whereIn('approval_status', ['published', 'approved'])
            ->when($category && $category !== 'All Categories', function ($q) use ($category) {
                if (is_numeric($category)) {
                    $q->where('main_category_id', $category);
                } else {
                    $q->whereHas('mainCategory', fn ($sub) => $sub->where('name', 'like', "%{$category}%"));
                }
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($minPrice, fn ($q) => $q->where('base_price', '>=', $minPrice))
            ->when($maxPrice, fn ($q) => $q->where('base_price', '<=', $maxPrice))
            ->when($sort, function ($q) use ($sort) {
                match ($sort) {
                    'price_asc'   => $q->orderBy('base_price', 'asc'),
                    'price_desc'  => $q->orderBy('base_price', 'desc'),
                    'rating_desc' => $q->orderBy('product_rating', 'desc'),
                    default       => $q->latest(),
                };
            }, fn ($q) => $q->latest());

        $listings = $query->paginate(15);

        $savedListingIds = SavedItem::where('account_id', $user->accountMember?->account_id ?? 1)
            ->where('item_type', 'listing')
            ->pluck('item_id')
            ->toArray();

        $data = collect($listings->items())->map(function ($listing) use ($savedListingIds, $request) {
            $supplierName = $listing->supplierAccount?->supplierProfile?->display_name
                ?? $listing->supplierAccount?->supplierProfile?->legal_name
                ?? $listing->supplierAccount?->display_name
                ?? 'Educational Supplier';

            $mediaItem = $listing->media->first(function ($m) {
                return str_starts_with($m->mime_type ?? '', 'image/');
            }) ?? $listing->media->first();

            $imageUrl = null;
            if ($mediaItem && !str_ends_with(strtolower($mediaItem->file_name ?? ''), '.mp4')) {
                $url = $mediaItem->getUrl();
                $parsed = parse_url($url);
                $path = $parsed['path'] ?? '';
                $imageUrl = $request->schemeAndHttpHost() . $path;
            }

            return [
                'id'                     => $listing->id,
                'title'                  => $listing->name,
                'name'                   => $listing->name,
                'slug'                   => $listing->slug ?? ('item-' . $listing->id),
                'sku'                    => $listing->sku ?? ('SKU-' . $listing->id),
                'listing_type'           => 'product',
                'category_name'          => $listing->mainCategory?->name ?? 'Classroom Supplies',
                'category_id'            => $listing->main_category_id,
                'base_price'             => (float) ($listing->base_price ?? 0),
                'currency_code'          => $listing->currency_code ?? 'USD',
                'minimum_order_quantity' => (int) ($listing->min_order_quantity ?? 1),
                'unit_name'              => 'Piece',
                'brand_name'             => 'Standard Academic',
                'supplier_id'            => $listing->supplier_account_id,
                'supplier_name'          => $supplierName,
                'image_url'              => $imageUrl,
                'rating'                 => (float) ($listing->product_rating ?? 4.8),
                'description'            => $listing->description ?? '',
                'specifications'         => [],
                'is_saved'               => in_array($listing->id, $savedListingIds),
            ];
        });

        return response()->json([
            'data'         => $data,
            'current_page' => $listings->currentPage(),
            'last_page'    => $listings->lastPage(),
            'total'        => $listings->total(),
        ]);
    }

    public function productDetail(Request $request, $idOrSlug): JsonResponse
    {
        $user = $this->currentUser($request);

        $listing = Listing::products()
            ->where(function ($q) use ($idOrSlug) {
                if (is_numeric($idOrSlug)) {
                    $q->where('id', $idOrSlug)->orWhere('slug', $idOrSlug);
                } else {
                    $q->where('slug', $idOrSlug);
                }
            })
            ->whereIn('approval_status', ['published', 'approved'])
            ->with([
                'mainCategory',
                'brand',
                'unit',
                'primaryImage',
                'media',
                'supplierAccount.supplierProfile.country',
                'attributeValues.attribute.unit',
                'attributeValues.attributeValue',
                'allTierPrices',
                'productReviews' => fn ($q) => $q->published()->with('buyerAccount.buyerProfile')->latest('published_at'),
            ])
            ->first();

        if (!$listing) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        // Images gallery
        $images = [];
        $gallery = $listing->getMedia('gallery');
        if ($gallery->isEmpty() && $listing->primaryImage) {
            $gallery = collect([$listing->primaryImage]);
        }
        if ($gallery->isEmpty() && $listing->media->isNotEmpty()) {
            $gallery = $listing->media->filter(fn ($m) => str_starts_with($m->mime_type ?? '', 'image/'));
        }
        foreach ($gallery as $m) {
            $url = $m->getUrl();
            $parsed = parse_url($url);
            $path = $parsed['path'] ?? '';
            $images[] = $request->schemeAndHttpHost() . $path;
        }

        // Videos
        $posters = $listing->getMedia('video_posters')->keyBy('id');
        $videos = $listing->getMedia('videos')->map(fn ($video) => [
            'url'    => $video->getUrl(),
            'poster' => ($poster = $posters->get($video->getCustomProperty('poster_id'))) ? $poster->getUrl() : null,
        ])->values()->all();

        // Tier prices
        $tierPrices = $listing->allTierPrices->map(fn ($tier) => [
            'min_quantity' => (int) $tier->min_quantity,
            'max_quantity' => $tier->max_quantity ? (int) $tier->max_quantity : null,
            'unit_price'   => (float) $tier->unit_price,
        ])->values()->all();

        // Specifications
        $specifications = [];
        foreach ($listing->attributeValues as $spec) {
            $attrName = $spec->attribute?->name ?? 'Attribute';
            if ($spec->attribute?->unit) {
                $unitSymbol = $spec->attribute->unit->symbol ?? $spec->attribute->unit->name;
                $attrName .= " ($unitSymbol)";
            }
            $val = method_exists($spec, 'formattedValue')
                ? $spec->formattedValue()
                : ($spec->attributeValue?->value ?? $spec->text_value ?? $spec->value ?? '');
            $specifications[] = [
                'name'  => $attrName,
                'value' => (string) $val,
            ];
        }

        // Supplier info
        $supplierProfile = $listing->supplierAccount?->supplierProfile;
        $supplierName = $supplierProfile?->display_name
            ?? $supplierProfile?->legal_name
            ?? $listing->supplierAccount?->display_name
            ?? 'Educational Supplier';

        $dealsCount = Review::where('supplier_account_id', $listing->supplier_account_id)
            ->purchaseExperience()
            ->published()
            ->count();

        $yearsActive = $supplierProfile?->founded_year
            ? now()->year - $supplierProfile->founded_year
            : null;

        $reviews = $listing->productReviews->map(fn ($rev) => [
            'id'         => $rev->id,
            'rating'     => (float) $rev->rating,
            'title'      => $rev->title ?? '',
            'comment'    => $rev->comment ?? '',
            'buyer_name' => $rev->buyerAccount?->buyerProfile?->display_name ?? 'Verified Buyer',
            'created_at' => $rev->published_at?->format('M d, Y') ?? $rev->created_at?->format('M d, Y'),
        ])->values()->all();

        $isSaved = false;
        if ($user) {
            $isSaved = SavedItem::where('account_id', $user->accountMember?->account_id ?? 1)
                ->where('item_type', 'listing')
                ->where('item_id', $listing->id)
                ->exists();
        }

        // Related products
        $relatedProducts = Listing::products()
            ->where('main_category_id', $listing->main_category_id)
            ->where('id', '!=', $listing->id)
            ->whereIn('approval_status', ['published', 'approved'])
            ->with(['mainCategory', 'supplierAccount.supplierProfile', 'media'])
            ->limit(4)
            ->get()
            ->map(function ($rel) use ($request) {
                $mediaItem = $rel->media->first(fn ($m) => str_starts_with($m->mime_type ?? '', 'image/')) ?? $rel->media->first();
                $img = null;
                if ($mediaItem && !str_ends_with(strtolower($mediaItem->file_name ?? ''), '.mp4')) {
                    $url = $mediaItem->getUrl();
                    $parsed = parse_url($url);
                    $path = $parsed['path'] ?? '';
                    $img = $request->schemeAndHttpHost() . $path;
                }
                return [
                    'id'            => $rel->id,
                    'name'          => $rel->name,
                    'slug'          => $rel->slug ?? ('item-' . $rel->id),
                    'base_price'    => (float) ($rel->base_price ?? 0),
                    'currency_code' => $rel->currency_code ?? 'USD',
                    'image_url'     => $img,
                    'rating'        => (float) ($rel->product_rating ?? 4.8),
                    'supplier_name' => $rel->supplierAccount?->supplierProfile?->display_name ?? 'Educational Supplier',
                ];
            })->values()->all();

        $priceMin = $listing->allTierPrices->min('unit_price') ?? $listing->base_price;
        $priceMax = $listing->allTierPrices->max('unit_price') ?? $listing->base_price;

        return response()->json([
            'id'                     => $listing->id,
            'title'                  => $listing->name,
            'name'                   => $listing->name,
            'slug'                   => $listing->slug ?? ('item-' . $listing->id),
            'sku'                    => $listing->sku ?? ('SKU-' . $listing->id),
            'listing_type'           => 'product',
            'category_name'          => $listing->mainCategory?->name ?? 'Classroom Supplies',
            'category_id'            => $listing->main_category_id,
            'base_price'             => (float) ($listing->base_price ?? 0),
            'price_min'              => (float) ($priceMin ?? 0),
            'price_max'              => (float) ($priceMax ?? 0),
            'currency_code'          => $listing->currency_code ?? 'USD',
            'minimum_order_quantity' => (int) ($listing->min_order_quantity ?? 1),
            'unit_name'              => $listing->unit?->name ?? 'Piece',
            'brand_name'             => $listing->brand?->name ?? 'Standard Academic',
            'supplier_id'            => $listing->supplier_account_id,
            'supplier_name'          => $supplierName,
            'supplier_country'       => $supplierProfile?->country?->name ?? 'Global',
            'supplier_city'          => $supplierProfile?->city ?? '',
            'supplier_verified'      => (bool) ($supplierProfile?->is_verified ?? true),
            'deals_count'            => $dealsCount,
            'years_active'           => $yearsActive,
            'image_url'              => $images[0] ?? null,
            'images'                 => $images,
            'videos'                 => $videos,
            'rating'                 => (float) ($listing->product_rating ?? 4.8),
            'reviews_count'          => (int) ($listing->product_reviews_count ?? count($reviews)),
            'description'            => $listing->description ?? '',
            'specifications'         => $specifications,
            'tier_prices'            => $tierPrices,
            'reviews'                => $reviews,
            'related_products'       => $relatedProducts,
            'is_saved'               => $isSaved,
        ]);
    }

    public function services(Request $request): JsonResponse
    {
        $search = $request->query('q') ?? $request->query('search');
        $category = $request->query('category');

        $query = Listing::services()
            ->with(['mainCategory', 'supplierAccount.supplierProfile', 'media'])
            ->when($category && $category !== 'All Categories', function ($q) use ($category) {
                if (is_numeric($category)) {
                    $q->where('main_category_id', $category);
                } else {
                    $q->whereHas('mainCategory', fn ($sub) => $sub->where('name', 'like', "%{$category}%"));
                }
            })
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->latest();

        $listings = $query->paginate(15);

        $data = collect($listings->items())->map(function ($l) {
            return [
                'id'            => $l->id,
                'title'         => $l->name,
                'name'          => $l->name,
                'category_name' => $l->mainCategory?->name ?? 'Educational Services',
                'base_price'    => (float) ($l->base_price ?? 0),
                'currency_code' => $l->currency_code ?? 'USD',
                'supplier_name' => $l->supplierAccount?->display_name ?? 'Service Provider',
                'description'   => $l->description ?? '',
            ];
        });

        return response()->json([
            'data'         => $data,
            'current_page' => $listings->currentPage(),
            'last_page'    => $listings->lastPage(),
            'total'        => $listings->total(),
        ]);
    }

    public function categories(): JsonResponse
    {
        $categories = Category::whereNull('parent_id')
            ->withCount('listings')
            ->get();

        $data = $categories->map(function ($c) {
            return [
                'id'             => $c->id,
                'name'           => $c->name,
                'slug'           => $c->slug,
                'listings_count' => $c->listings_count,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $search = $request->query('q') ?? $request->query('search');
        $verifiedOnly = $request->boolean('verified');

        $suppliers = SupplierProfile::with([
            'account.listings.mainCategory',
            'account.locations.city',
            'account.locations.country',
        ])
            ->when($search, fn ($q) => $q->where('display_name', 'like', "%{$search}%")->orWhere('legal_name', 'like', "%{$search}%"))
            ->when($verifiedOnly, fn ($q) => $q->whereHas('account', fn ($a) => $a->where('status', 'active')))
            ->paginate(15);

        $data = collect($suppliers->items())->map(function ($s) use ($request) {
            $logoUrl = $s->logo ? $request->schemeAndHttpHost() . '/storage/' . ltrim($s->logo, '/') : null;
            $bannerUrl = $s->banner ? $request->schemeAndHttpHost() . '/storage/' . ltrim($s->banner, '/') : null;

            $primaryLocation = $s->account?->locations?->first();
            $locationText = null;
            if ($primaryLocation && $primaryLocation->city?->name) {
                $locationText = $primaryLocation->city->name . ($primaryLocation->country?->name ? ', ' . $primaryLocation->country->name : '');
            } elseif ($s->website) {
                $locationText = $s->website;
            } else {
                $locationText = 'Verified Academic Supplier';
            }

            $categories = $s->account?->listings
                ?->pluck('mainCategory.name')
                ->filter()
                ->unique()
                ->values()
                ->all() ?? [];

            return [
                'id'             => $s->id,
                'account_id'     => $s->account_id,
                'company_name'   => $s->display_name ?? $s->legal_name ?? 'Educational Supplier',
                'display_name'   => $s->display_name ?? $s->legal_name ?? 'Educational Supplier',
                'slug'           => $s->slug ?? ('supplier-' . $s->id),
                'rating'         => (float) ($s->rating ?? 4.8),
                'verified'       => $s->account?->status === 'active',
                'is_verified'    => $s->account?->status === 'active',
                'logo_url'       => $logoUrl,
                'banner_url'     => $bannerUrl,
                'contact_email'  => $s->contact_email,
                'categories'     => $categories,
                'listings_count' => $s->account?->listings?->count() ?? 0,
                'location'       => $locationText,
                'description'    => $s->description ?? 'Certified school & university supplier.',
            ];
        });

        return response()->json([
            'data'         => $data,
            'current_page' => $suppliers->currentPage(),
            'last_page'    => $suppliers->lastPage(),
            'total'        => $suppliers->total(),
        ]);
    }

    public function supplierDetail(Request $request, $idOrSlug): JsonResponse
    {
        $user = $this->currentUser($request);

        $supplier = SupplierProfile::where(function ($q) use ($idOrSlug) {
            if (is_numeric($idOrSlug)) {
                $q->where('id', $idOrSlug)->orWhere('account_id', $idOrSlug)->orWhere('slug', $idOrSlug);
            } else {
                $q->where('slug', $idOrSlug);
            }
        })
        ->with([
            'country',
            'city',
            'account.supplierTypes',
            'account.locations.city',
            'account.locations.country',
            'account.socialLinks.platform',
            'businessHours',
            'gallery' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
            'videos' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
        ])
        ->first();

        if (!$supplier) {
            return response()->json(['message' => 'Supplier not found'], 404);
        }

        $logoUrl = $supplier->logo ? $request->schemeAndHttpHost() . '/storage/' . ltrim($supplier->logo, '/') : null;
        $bannerUrl = $supplier->banner ? $request->schemeAndHttpHost() . '/storage/' . ltrim($supplier->banner, '/') : null;
        $profilePhotoUrl = $supplier->profile_photo ? $request->schemeAndHttpHost() . '/storage/' . ltrim($supplier->profile_photo, '/') : null;

        $primaryLocation = $supplier->account?->locations?->first();
        $locationText = null;
        if ($primaryLocation && $primaryLocation->city?->name) {
            $locationText = $primaryLocation->city->name . ($primaryLocation->country?->name ? ', ' . $primaryLocation->country->name : '');
        } elseif ($supplier->website) {
            $locationText = $supplier->website;
        } else {
            $locationText = 'Verified Academic Supplier';
        }

        $products = Listing::products()
            ->where('supplier_account_id', $supplier->account_id)
            ->whereIn('approval_status', ['published', 'approved'])
            ->with(['mainCategory', 'media'])
            ->limit(12)
            ->get()
            ->map(function ($l) use ($request) {
                $mediaItem = $l->media->first(fn ($m) => str_starts_with($m->mime_type ?? '', 'image/')) ?? $l->media->first();
                $imageUrl = null;
                if ($mediaItem && !str_ends_with(strtolower($mediaItem->file_name ?? ''), '.mp4')) {
                    $url = $mediaItem->getUrl();
                    $parsed = parse_url($url);
                    $path = $parsed['path'] ?? '';
                    $imageUrl = $request->schemeAndHttpHost() . $path;
                }
                return [
                    'id'                     => $l->id,
                    'name'                   => $l->name,
                    'title'                  => $l->name,
                    'slug'                   => $l->slug ?? ('item-' . $l->id),
                    'base_price'             => (float) ($l->base_price ?? 0),
                    'currency_code'          => $l->currency_code ?? 'USD',
                    'category_name'          => $l->mainCategory?->name ?? 'Classroom Supplies',
                    'minimum_order_quantity' => (int) ($l->min_order_quantity ?? 1),
                    'unit_name'              => 'Piece',
                    'image_url'              => $imageUrl,
                    'rating'                 => (float) ($l->product_rating ?? 4.8),
                ];
            })->values()->all();

        $services = [];
        if ($supplier->account) {
            $services = $supplier->account->services()
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($srv) => [
                    'id'          => $srv->id,
                    'name'        => $srv->name,
                    'title'       => $srv->name,
                    'description' => $srv->description ?? '',
                ])->values()->all();
        }

        $certifications = [];
        $achievements = [];
        if ($supplier->account) {
            $certifications = $supplier->account->certifications()
                ->approved()
                ->latest()
                ->get()
                ->map(fn ($c) => [
                    'id'                 => $c->id,
                    'name'               => $c->name,
                    'issuer'             => $c->issuing_organization ?? $c->issuer ?? '',
                    'certificate_number' => $c->certificate_number ?? '',
                    'issue_date'         => $c->issue_date ? $c->issue_date->format('M Y') : null,
                ])->values()->all();

            $achievements = $supplier->account->accountAchievements()
                ->approved()
                ->with('achievement')
                ->get()
                ->pluck('achievement')
                ->filter()
                ->map(fn ($ach) => [
                    'id'          => $ach->id,
                    'name'        => $ach->name,
                    'badge_label' => method_exists($ach, 'badgeLabel') ? $ach->badgeLabel() : $ach->name,
                ])->values()->all();
        }

        $reviews = Review::where('supplier_account_id', $supplier->account_id)
            ->supplier()
            ->published()
            ->with('buyerAccount.buyerProfile')
            ->latest('published_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id'         => $r->id,
                'rating'     => (float) $r->rating,
                'title'      => $r->title ?? '',
                'comment'    => $r->comment ?? '',
                'buyer_name' => $r->buyerAccount?->buyerProfile?->display_name ?? 'Verified Institution',
                'created_at' => $r->published_at?->format('M d, Y') ?? $r->created_at?->format('M d, Y'),
            ])->values()->all();

        $rfqsCompleted = Quotation::where('supplier_account_id', $supplier->account_id)
            ->where('status', 'awarded')
            ->count();

        $yearsInBusiness = $supplier->founded_year ? now()->year - $supplier->founded_year : null;
        $avgResponseHours = $supplier->average_response_minutes ? round($supplier->average_response_minutes / 60, 1) : null;
        $isWorldwide = method_exists($supplier, 'isWorldwideSupplier') ? $supplier->isWorldwideSupplier() : false;

        $gallery = $supplier->gallery->map(fn ($g) => [
            'id'       => $g->id,
            'caption'  => $g->caption ?? '',
            'url'      => $request->schemeAndHttpHost() . '/storage/' . ltrim($g->file_path, '/'),
        ])->values()->all();

        $isSaved = false;
        if ($user) {
            $isSaved = SavedItem::where('account_id', $user->accountMember?->account_id ?? 1)
                ->where('item_type', 'supplier')
                ->where('item_id', $supplier->account_id)
                ->exists();
        }

        $supplierTypes = $supplier->account?->supplierTypes?->pluck('name')->values()->all() ?? [];

        return response()->json([
            'id'                       => $supplier->id,
            'account_id'               => $supplier->account_id,
            'display_name'             => $supplier->display_name ?? $supplier->legal_name ?? 'Educational Supplier',
            'company_name'             => $supplier->legal_name ?? $supplier->display_name ?? 'Educational Supplier',
            'slug'                     => $supplier->slug ?? ('supplier-' . $supplier->id),
            'rating'                   => (float) ($supplier->rating ?? 4.9),
            'reviews_count'            => (int) ($supplier->reviews_count ?? count($reviews)),
            'is_verified'              => $supplier->account?->status === 'active',
            'verified'                 => $supplier->account?->status === 'active',
            'logo_url'                 => $logoUrl ?? $profilePhotoUrl,
            'banner_url'               => $bannerUrl,
            'location'                 => $locationText,
            'country'                  => $supplier->country?->name ?? 'Global',
            'city'                     => $supplier->city?->name ?? '',
            'contact_email'            => $supplier->contact_email,
            'contact_phone'            => $supplier->contact_phone,
            'website'                  => $supplier->website,
            'description'              => $supplier->description ?? '',
            'about'                    => $supplier->about ?? $supplier->description ?? '',
            'founded_year'             => $supplier->founded_year,
            'years_in_business'        => $yearsInBusiness,
            'response_rate'            => $supplier->quotation_response_rate !== null ? round((float) $supplier->quotation_response_rate) : 98,
            'avg_response_hours'       => $avgResponseHours ?? 2.5,
            'rfqs_completed'           => $rfqsCompleted,
            'is_worldwide'             => $isWorldwide,
            'countries_served_display' => $isWorldwide ? 'Worldwide' : '15+',
            'supplier_types'           => $supplierTypes,
            'products'                 => $products,
            'products_count'           => count($products),
            'services'                 => $services,
            'certifications'           => $certifications,
            'achievements'             => $achievements,
            'gallery'                  => $gallery,
            'reviews'                  => $reviews,
            'is_saved'                 => $isSaved,
        ]);
    }

    public function blogPosts(Request $request): JsonResponse
    {
        $search = $request->query('q') ?? $request->query('search');
        $category = $request->query('category');

        $query = BlogPost::with(['category', 'account', 'authorUser'])
            ->where('status', 'approved')
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', "%{$search}%")
                        ->orWhere('excerpt', 'like', "%{$search}%");
                });
            })
            ->when($category, function ($q) use ($category) {
                $q->whereHas('category', fn ($c) => $c->where('slug', $category)->orWhere('name', $category));
            })
            ->latest('published_at');

        $posts = $query->paginate(10);

        $data = collect($posts->items())->map(function ($post) use ($request) {
            $imageUrl = null;
            if ($post->cover_image) {
                $imageUrl = $request->schemeAndHttpHost() . '/storage/' . ltrim($post->cover_image, '/');
            }

            return [
                'id'                   => $post->id,
                'title'                => $post->title,
                'slug'                 => $post->slug,
                'excerpt'              => $post->excerpt ?? Str::limit(strip_tags($post->content ?? ''), 120),
                'tag'                  => $post->category?->name ?? 'Procurement',
                'category_name'        => $post->category?->name ?? 'Procurement',
                'image_url'            => $imageUrl,
                'reading_time_minutes' => (int) ($post->reading_time_minutes ?? 5),
                'views_count'          => (int) ($post->views_count ?? 0),
                'author_name'          => $post->authorUser?->name ?? $post->account?->display_name ?? 'EduShopify Editorial',
                'published_at'         => $post->published_at?->toIso8601String() ?? $post->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data'         => $data,
            'current_page' => $posts->currentPage(),
            'last_page'    => $posts->lastPage(),
            'total'        => $posts->total(),
        ]);
    }

    public function blogPostDetail(Request $request, $idOrSlug): JsonResponse
    {
        $post = BlogPost::with(['category', 'account', 'authorUser'])
            ->where(function ($q) use ($idOrSlug) {
                if (is_numeric($idOrSlug)) {
                    $q->where('id', $idOrSlug)->orWhere('slug', $idOrSlug);
                } else {
                    $q->where('slug', $idOrSlug);
                }
            })
            ->where('status', 'approved')
            ->first();

        if (!$post) {
            return response()->json(['message' => 'Blog post not found'], 404);
        }

        // Increment views count silently
        $post->increment('views_count');

        $imageUrl = null;
        if ($post->cover_image) {
            $imageUrl = $request->schemeAndHttpHost() . '/storage/' . ltrim($post->cover_image, '/');
        }

        $relatedPosts = BlogPost::where('id', '!=', $post->id)
            ->where('status', 'approved')
            ->when($post->category_id, fn ($q) => $q->where('category_id', $post->category_id))
            ->limit(3)
            ->get()
            ->map(function ($rel) use ($request) {
                $relImg = null;
                if ($rel->cover_image) {
                    $relImg = $request->schemeAndHttpHost() . '/storage/' . ltrim($rel->cover_image, '/');
                }
                return [
                    'id'                   => $rel->id,
                    'title'                => $rel->title,
                    'slug'                 => $rel->slug,
                    'excerpt'              => $rel->excerpt ?? Str::limit(strip_tags($rel->content ?? ''), 100),
                    'tag'                  => $rel->category?->name ?? 'Article',
                    'image_url'            => $relImg,
                    'reading_time_minutes' => (int) ($rel->reading_time_minutes ?? 4),
                    'views_count'          => (int) ($rel->views_count ?? 0),
                    'published_at'         => $rel->published_at?->toIso8601String() ?? $rel->created_at?->toIso8601String(),
                ];
            })->values()->all();

        return response()->json([
            'id'                   => $post->id,
            'title'                => $post->title,
            'slug'                 => $post->slug,
            'excerpt'              => $post->excerpt ?? Str::limit(strip_tags($post->content ?? ''), 160),
            'content'              => $post->content ?? '',
            'tag'                  => $post->category?->name ?? 'Procurement Insight',
            'category_name'        => $post->category?->name ?? 'Procurement Insight',
            'category_id'          => $post->category_id,
            'image_url'            => $imageUrl,
            'reading_time_minutes' => (int) ($post->reading_time_minutes ?? 5),
            'views_count'          => (int) ($post->views_count ?? 0),
            'author_name'          => $post->authorUser?->name ?? $post->account?->display_name ?? 'EduShopify Editorial',
            'author_role'          => 'Educational Procurement Specialist',
            'published_at'         => $post->published_at?->toIso8601String() ?? $post->created_at?->toIso8601String(),
            'related_posts'        => $relatedPosts,
        ]);
    }

    public function resources(Request $request): JsonResponse
    {
        $resources = [
            [
                'id'        => 1,
                'title'     => 'How to Build a Future-Ready STEM Lab',
                'subtitle'  => 'Complete planning guide, equipment checklist, and safety standards.',
                'tag'       => 'STEM Education',
                'image_url' => 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=400&q=80',
                'route'     => '/resources',
            ],
            [
                'id'        => 2,
                'title'     => 'Interactive Display Buying Guide for Schools',
                'subtitle'  => 'Resolution, touch capability, software licensing, and TCO considerations.',
                'tag'       => 'Smart Classroom',
                'image_url' => 'https://images.unsplash.com/photo-1577896851231-70ef18881754?auto=format&fit=crop&w=400&q=80',
                'route'     => '/resources',
            ],
            [
                'id'        => 3,
                'title'     => 'Start a Request for Quotation (RFQ)',
                'subtitle'  => 'Publish detailed RFQs to receive competitive bids from certified suppliers.',
                'tag'       => 'Procurement Guide',
                'image_url' => 'https://images.unsplash.com/photo-1499750310107-5fef28a66643?w=400&auto=format&fit=crop&q=80',
                'route'     => '/rfq/create',
            ],
            [
                'id'        => 4,
                'title'     => 'Verified Vendor Guarantee & Accreditation',
                'subtitle'  => 'Every supplier undergoes strict institutional compliance checks.',
                'tag'       => 'Trust & Safety',
                'image_url' => 'https://images.unsplash.com/photo-1600880292203-757bb62b4baf?w=400&auto=format&fit=crop&q=80',
                'route'     => '/resources',
            ],
            [
                'id'        => 5,
                'title'     => 'Bulk Order Discounts & Institutional Savings',
                'subtitle'  => 'Strategies for maximizing your annual institutional procurement budget.',
                'tag'       => 'Cost Optimization',
                'image_url' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=400&auto=format&fit=crop&q=80',
                'route'     => '/resources',
            ],
            [
                'id'        => 6,
                'title'     => 'AI in Education: Implementation Guide',
                'subtitle'  => 'Safely introducing artificial intelligence kits into K-12 classrooms.',
                'tag'       => 'AI & Technology',
                'image_url' => 'https://images.unsplash.com/photo-1485827404703-89b55fcc595e?auto=format&fit=crop&w=400&q=80',
                'route'     => '/resources',
            ],
        ];

        $categories = [
            [
                'id'          => 'buyer-guides',
                'title'       => 'Buyer Guides',
                'count'       => '120+ Guides',
                'description' => 'In-depth guides to streamline institutional purchasing',
                'icon'        => 'menu_book',
                'color'       => '#2563eb',
                'bg_color'    => '#eff6ff',
            ],
            [
                'id'          => 'procurement-insights',
                'title'       => 'Procurement Insights',
                'count'       => '85+ Articles',
                'description' => 'Data-driven trends, spending reports & market analysis',
                'icon'        => 'insights',
                'color'       => '#9333ea',
                'bg_color'    => '#faf5ff',
            ],
            [
                'id'          => 'product-launches',
                'title'       => 'Product Launches',
                'count'       => '60+ Updates',
                'description' => 'Latest innovations & product releases in education',
                'icon'        => 'rocket_launch',
                'color'       => '#d97706',
                'bg_color'    => '#fffbeb',
            ],
            [
                'id'          => 'education-news',
                'title'       => 'Education News',
                'count'       => '240+ Stories',
                'description' => 'Policy updates, global developments & industry shifts',
                'icon'        => 'newspaper',
                'color'       => '#e11d48',
                'bg_color'    => '#fff1f2',
            ],
            [
                'id'          => 'events-exhibitions',
                'title'       => 'Events & Exhibitions',
                'count'       => '50+ Global',
                'description' => 'Global conferences, expos & trade fairs for educators',
                'icon'        => 'event',
                'color'       => '#059669',
                'bg_color'    => '#ecfdf5',
            ],
            [
                'id'          => 'rfq-templates',
                'title'       => 'RFQ Templates',
                'count'       => '350+ Files',
                'description' => 'Ready-to-use procurement templates & specification checklists',
                'icon'        => 'description',
                'color'       => '#0d9488',
                'bg_color'    => '#f0fdfa',
            ],
            [
                'id'          => 'case-studies',
                'title'       => 'Case Studies',
                'count'       => '45+ Stories',
                'description' => 'Real-world stories of schools transforming learning with tech',
                'icon'        => 'auto_stories',
                'color'       => '#4f46e5',
                'bg_color'    => '#eef2ff',
            ],
        ];

        $featuredGuides = [
            [
                'id'                   => 1,
                'title'                => 'How to Build a Future-Ready STEM Lab',
                'subtitle'             => 'Complete planning guide, equipment list, safety standards and lab layout tips.',
                'tag'                  => 'STEM Education',
                'reading_time_minutes' => 12,
                'image_url'            => 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=600&q=80',
            ],
            [
                'id'                   => 2,
                'title'                => 'Interactive Display Buying Guide for Schools',
                'subtitle'             => 'Resolution, touch capability, software licensing, warranty and total cost of ownership.',
                'tag'                  => 'Smart Classroom',
                'reading_time_minutes' => 9,
                'image_url'            => 'https://images.unsplash.com/photo-1577896851231-70ef18881754?auto=format&fit=crop&w=600&q=80',
            ],
            [
                'id'                   => 6,
                'title'                => 'AI in Education: Implementation Guide',
                'subtitle'             => 'Step-by-step roadmap to integrate artificial intelligence tools safely into classrooms.',
                'tag'                  => 'AI & Technology',
                'reading_time_minutes' => 15,
                'image_url'            => 'https://images.unsplash.com/photo-1485827404703-89b55fcc595e?auto=format&fit=crop&w=600&q=80',
            ],
        ];

        $latestInsights = [
            [
                'id'                   => 101,
                'title'                => 'Top 10 Education Procurement Trends in 2026',
                'subtitle'             => 'Key trends shaping institutional buying, digital adoption, and multi-vendor contracts.',
                'tag'                  => 'Procurement Trends',
                'reading_time_minutes' => 8,
                'image_url'            => 'https://images.unsplash.com/photo-1552664730-d307ca884978?auto=format&fit=crop&w=600&q=80',
            ],
            [
                'id'                   => 102,
                'title'                => 'Most Requested Products in RFQ Marketplace',
                'subtitle'             => 'Aggregated insights from 10,000+ institution tenders posted in Q1 2026.',
                'tag'                  => 'Market Trends',
                'reading_time_minutes' => 6,
                'image_url'            => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=600&q=80',
            ],
            [
                'id'                   => 103,
                'title'                => 'Global Education Spending Report 2026',
                'subtitle'             => 'Country-wise allocation, STEM stimulus packages, and edtech infrastructure forecasts.',
                'tag'                  => 'Financial Report',
                'reading_time_minutes' => 10,
                'image_url'            => 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=600&q=80',
            ],
        ];

        $upcomingEvents = [
            [
                'id'          => 1,
                'name'        => 'BETT London 2026',
                'short_code'  => 'BETT',
                'location'    => 'London, UK',
                'date_range'  => '22 - 24 Jan 2026',
                'description' => 'The world’s leading education technology community exhibition.',
                'badge_color' => '#059669',
            ],
            [
                'id'          => 2,
                'name'        => 'GESS Dubai 2026',
                'short_code'  => 'GESS',
                'location'    => 'Dubai, UAE',
                'date_range'  => '11 - 13 Feb 2026',
                'description' => 'Global educational supplies & solutions exhibition for MENA region.',
                'badge_color' => '#2563eb',
            ],
            [
                'id'          => 3,
                'name'        => 'ISE Barcelona 2026',
                'short_code'  => 'ISE',
                'location'    => 'Barcelona, Spain',
                'date_range'  => '03 - 06 Feb 2026',
                'description' => 'Integrated Systems Europe audio-visual & smart education summit.',
                'badge_color' => '#d97706',
            ],
            [
                'id'          => 4,
                'name'        => 'ISTE Live 2026',
                'short_code'  => 'ISTE',
                'location'    => 'San Antonio, USA',
                'date_range'  => '29 Jun - 01 Jul 2026',
                'description' => 'International Society for Technology in Education annual conference.',
                'badge_color' => '#9333ea',
            ],
        ];

        $rfqTemplates = [
            [
                'id'          => 1,
                'title'       => 'STEM Lab RFQ Template',
                'description' => 'Customizable specifications checklist for robotics, 3D printers, and sensors.',
                'category'    => 'STEM & Robotics',
                'file_format' => 'PDF / DOCX',
                'file_size'   => '1.8 MB',
            ],
            [
                'id'          => 2,
                'title'       => 'Interactive Display RFQ Template',
                'description' => 'Technical specifications for 65"-86" 4K interactive panels and wall mounts.',
                'category'    => 'Smart Classroom',
                'file_format' => 'PDF / DOCX',
                'file_size'   => '1.2 MB',
            ],
            [
                'id'          => 3,
                'title'       => 'Computer Lab RFQ Template',
                'description' => 'Procurement requirements for student PCs, server rack, and networking switches.',
                'category'    => 'IT Infrastructure',
                'file_format' => 'PDF / DOCX',
                'file_size'   => '2.1 MB',
            ],
            [
                'id'          => 4,
                'title'       => 'Science Lab RFQ Template',
                'description' => 'Comprehensive procurement checklist for glassware, fume hoods, and safety gear.',
                'category'    => 'Science Equipment',
                'file_format' => 'PDF / DOCX',
                'file_size'   => '1.5 MB',
            ],
        ];

        $stats = [
            'guides_count'       => '1,250+',
            'templates_count'    => '350+',
            'free_access'        => '100%',
            'global_exhibitions' => '50+',
        ];

        $features = [
            [
                'title'       => 'Expert Content',
                'description' => 'Created by industry experts and education professionals',
                'icon'        => 'verified',
            ],
            [
                'title'       => 'Free & Accessible',
                'description' => 'All resources are free to access and download',
                'icon'        => 'lock_open',
            ],
            [
                'title'       => 'Practical & Actionable',
                'description' => 'Real-world insights you can implement immediately',
                'icon'        => 'lightbulb',
            ],
            [
                'title'       => 'Global Perspective',
                'description' => 'Insights from 85+ countries and global education market',
                'icon'        => 'public',
            ],
            [
                'title'       => 'Trusted by Thousands',
                'description' => 'Used by schools, universities and buyers worldwide',
                'icon'        => 'groups',
            ],
        ];

        return response()->json([
            'data'            => $resources,
            'categories'      => $categories,
            'featured_guides' => $featuredGuides,
            'latest_insights' => $latestInsights,
            'upcoming_events' => $upcomingEvents,
            'rfq_templates'   => $rfqTemplates,
            'stats'           => $stats,
            'features'        => $features,
        ]);
    }

    public function resourceDetail(Request $request, $idOrSlug): JsonResponse
    {
        $id = is_numeric($idOrSlug) ? (int) $idOrSlug : 1;

        $guides = [
            1 => [
                'id'                   => 1,
                'title'                => 'How to Build a Future-Ready STEM Lab',
                'subtitle'             => 'Complete planning guide, equipment list, safety standards and lab layout tips.',
                'tag'                  => 'STEM Education',
                'reading_time_minutes' => 12,
                'views_count'          => 2840,
                'downloads_count'      => 450,
                'rating'               => 4.9,
                'published_at'         => '2026-02-15T09:00:00Z',
                'image_url'            => 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=1200&q=80',
                'author_name'          => 'Dr. Aris Thorne',
                'author_role'          => 'Director of STEM Infrastructure & Learning Spaces',
                'author_avatar'        => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=200&q=80',
                'summary'              => 'Setting up an impactful STEM and robotics environment requires balancing flexible physical infrastructure, high-throughput digital networking, certified hardware, and robust health & safety procedures. This comprehensive roadmap covers spatial zoning, equipment matrices, budgeting formulas, and standard tender requirements for K-12 and university makerspaces.',
                'sections'             => [
                    [
                        'id'      => 'sec-spatial',
                        'title'   => '1. Spatial Zoning & Infrastructure Requirements',
                        'content' => 'A future-ready STEM lab must accommodate three distinct operational zones: Design & Simulation (clean computer lab desks with dual monitors), Fabrication & Prototyping (dust and ventilation isolation for 3D printers, laser cutters, and soldering stations), and Collaboration/Testing (open movable tables and robotics arenas). Ensure minimum floor clearance of 4.5 square meters per student station.',
                        'bullet_points' => [
                            'Floor power pop-ups and overhead drop-down cord reels to eliminate trip hazards.',
                            'Dedicated exhaust extraction (minimum 250 CFM) for resin and FDM filament print farms.',
                            'ESD-safe conductive anti-static flooring in robotics assembly zones.',
                            'Modular wheeled benches with chemical-resistant epoxy resin work surfaces.',
                        ],
                    ],
                    [
                        'id'      => 'sec-equipment',
                        'title'   => '2. Essential Equipment & Kit Checklists',
                        'content' => 'Avoid purchasing fragmented consumer-grade kits without long-term parts availability and curriculum support. Institutional procurement committees should prioritize open-source microcontrollers with hardware protection diodes, industrial-grade closed-loop stepper 3D printers, and robust sensor arrays.',
                        'bullet_points' => [
                            'Core Prototyping: 4x Enclosed CoreXY 3D Printers with HEPA filtration (0.05 - 0.3mm tolerance).',
                            'Electronics Bench: 12x Arduino ESP32 / Raspberry Pi 5 starter stations with digital multimeters.',
                            'Robotics Platforms: Modular programmable rovers with ultrasonic and LiDAR obstacle avoidance.',
                            'Tools & Safety: Temperature-regulated soldering stations, fume extractors, and fire suppression blankets.',
                        ],
                    ],
                    [
                        'id'      => 'sec-budget',
                        'title'   => '3. Budget Allocation & Cost Benchmarking',
                        'content' => 'Based on analysis of 120+ institutional school lab tenders in 2025-2026, total initial setup budget typically breaks down into 45% Core Equipment & Hardware, 20% Spatial Furniture & Electrical, 15% Annual Consumables & Spare Modules, 10% Faculty Professional Development, and 10% Contingency & Freight.',
                        'bullet_points' => [
                            'Budget Tier A (Starter Lab / 20 Students): $18,000 - $25,000',
                            'Budget Tier B (Comprehensive Maker Lab / 30 Students): $35,000 - $60,000',
                            'Budget Tier C (Advanced Research & Innovation Hub): $75,000 - $120,000+',
                        ],
                    ],
                    [
                        'id'      => 'sec-compliance',
                        'title'   => '4. Safety Standards & Institutional Compliance',
                        'content' => 'All procuring institutions must insist on CE, FCC, RoHS, and UL-certified equipment. Laser cutters must feature interlock switches (Class 1 enclosure) preventing laser activation whenever the lid is opened. Chemical storage cabinets for flux and solvents must comply with NFPA 30 / OSHA standards.',
                        'bullet_points' => [
                            'Eye Wash Station and First Aid Station within 10 seconds unimpeded reach.',
                            'Emergency Electrical Master Kill-Switch prominently labeled at room exits.',
                            'Flammable storage cabinet with double-walled 18-gauge steel construction.',
                        ],
                    ],
                ],
                'checklists'           => [
                    ['item' => 'CoreXY enclosed 3D printers with dual extrusion', 'category' => 'Fabrication', 'mandatory' => true],
                    ['item' => 'Arduino Nano ESP32 student kits with sensor bundles', 'category' => 'Electronics', 'mandatory' => true],
                    ['item' => 'Fume extractor with activated carbon and HEPA filtration', 'category' => 'Safety', 'mandatory' => true],
                    ['item' => 'Heavy-duty mobile workbenches with butcher block tops', 'category' => 'Furniture', 'mandatory' => true],
                    ['item' => 'Digital storage oscilloscopes (2-channel, 50MHz+)', 'category' => 'Instrumentation', 'mandatory' => false],
                    ['item' => 'Class 1 enclosed CO2 laser cutter with air assist', 'category' => 'Fabrication', 'mandatory' => false],
                ],
                'downloadable_assets'  => [
                    [
                        'title'       => 'STEM Lab Equipment Specification Checklist',
                        'file_format' => 'PDF',
                        'file_size'   => '2.4 MB',
                        'description' => 'Detailed tender-ready procurement specification sheet with ASTM and safety standards.',
                    ],
                    [
                        'title'       => 'STEM Lab Budget Allocation Model & Calculator',
                        'file_format' => 'XLSX',
                        'file_size'   => '1.1 MB',
                        'description' => 'Customizable spreadsheet to forecast initial equipment investment and yearly consumables.',
                    ],
                ],
                'related_resources'    => [
                    [
                        'id'        => 2,
                        'title'     => 'Interactive Display Buying Guide for Schools',
                        'tag'       => 'Smart Classroom',
                        'image_url' => 'https://images.unsplash.com/photo-1577896851231-70ef18881754?auto=format&fit=crop&w=600&q=80',
                    ],
                    [
                        'id'        => 6,
                        'title'     => 'AI in Education: Implementation Guide',
                        'tag'       => 'AI & Technology',
                        'image_url' => 'https://images.unsplash.com/photo-1485827404703-89b55fcc595e?auto=format&fit=crop&w=600&q=80',
                    ],
                ],
            ],
            2 => [
                'id'                   => 2,
                'title'                => 'Interactive Display Buying Guide for Schools',
                'subtitle'             => 'Resolution, touch capability, software licensing, warranty and total cost of ownership.',
                'tag'                  => 'Smart Classroom',
                'reading_time_minutes' => 9,
                'views_count'          => 1920,
                'downloads_count'      => 310,
                'rating'               => 4.85,
                'published_at'         => '2026-02-10T11:00:00Z',
                'image_url'            => 'https://images.unsplash.com/photo-1577896851231-70ef18881754?auto=format&fit=crop&w=1200&q=80',
                'author_name'          => 'Elena Rostova',
                'author_role'          => 'EdTech Media & Digital Classroom Strategist',
                'author_avatar'        => 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w=200&q=80',
                'summary'              => 'Transitioning traditional blackboards to interactive flat panels (IFPD) is one of the highest-value classroom investments. This guide evaluates optical bonding, infrared vs capacitive touch, embedded Android vs OPS Windows slot-in PCs, and multi-year cloud device management.',
                'sections'             => [
                    [
                        'id'      => 'sec-display-size',
                        'title'   => '1. Sizing & Screen Resolution Standards',
                        'content' => 'For standard 25-30 student classrooms (depth up to 8 meters), 75-inch 4K panels represent the sweet spot for visual ergonomics. For larger lecture halls or spaces exceeding 10 meters depth, 86-inch displays are mandatory. Always specify 4K UHD (3840x2160) anti-glare toughened glass with 7H hardness.',
                        'bullet_points' => [
                            '65-inch: Small seminar rooms & huddle areas (up to 15 students).',
                            '75-inch: Standard K-12 classrooms (20-30 students).',
                            '86-inch+: Large multi-purpose rooms and science amphitheaters.',
                            'Zero-bonding or optical bonding to eliminate parallax error while writing.',
                        ],
                    ],
                    [
                        'id'      => 'sec-touch-tech',
                        'title'   => '2. Touch Latency & Multi-User Interaction',
                        'content' => 'High-frequency infrared (IR) touch with minimum 40 touch points and <5ms response latency provides natural handwriting experience with palm rejection. Dual stylus recognition with distinct nib sizes allows two students or teacher/student to write simultaneously in different colors.',
                        'bullet_points' => [
                            'Minimum 40-point touch with 1mm writing accuracy.',
                            'Zero latency stylus drawing with palm rejection algorithm.',
                            'Finger touch, pen write, and palm erase gesture shortcuts.',
                        ],
                    ],
                    [
                        'id'      => 'sec-ops-os',
                        'title'   => '3. OS Architecture: Android vs OPS Slot-in Module',
                        'content' => 'Demand Android 13 or 14 Enterprise Recommended firmware with Google EDLA (Enterprise Device Licensing Agreement) for native Google Play and Workspace access. For schools running specialized legacy Windows simulation software, specify open pluggable standard (OPS) Intel Core i5/i7 PC modules.',
                        'bullet_points' => [
                            'Native Google EDLA certification with verified Play Protect.',
                            'Standard 80-pin OPS slot allowing modular PC upgrades without replacing the panel.',
                            'Centralized Over-The-Air (OTA) firmware and app management portal.',
                        ],
                    ],
                ],
                'checklists'           => [
                    ['item' => '75" 4K UHD Anti-Glare 7H toughened glass panel', 'category' => 'Display', 'mandatory' => true],
                    ['item' => 'Zero-bonding display technology with 40-point IR touch', 'category' => 'Touch', 'mandatory' => true],
                    ['item' => 'Google EDLA certified Android OS with Play Store', 'category' => 'Software', 'mandatory' => true],
                    ['item' => 'Front-facing USB-C port with 65W Power Delivery & touch return', 'category' => 'Connectivity', 'mandatory' => true],
                    ['item' => 'OPS slot-in Intel Core i5 Windows 11 Pro module', 'category' => 'Hardware', 'mandatory' => false],
                    ['item' => 'Heavy-duty motorized height-adjustable mobile trolley cart', 'category' => 'Mounting', 'mandatory' => false],
                ],
                'downloadable_assets'  => [
                    [
                        'title'       => 'Interactive Flat Panel Procurement RFP Matrix',
                        'file_format' => 'PDF',
                        'file_size'   => '1.9 MB',
                        'description' => 'Complete checklist of technical benchmarks, contrast ratios, and software licensing specs.',
                    ],
                ],
                'related_resources'    => [
                    [
                        'id'        => 1,
                        'title'     => 'How to Build a Future-Ready STEM Lab',
                        'tag'       => 'STEM Education',
                        'image_url' => 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=600&q=80',
                    ],
                ],
            ],
            6 => [
                'id'                   => 6,
                'title'                => 'AI in Education: Implementation Guide',
                'subtitle'             => 'Step-by-step roadmap to integrate artificial intelligence tools safely into classrooms.',
                'tag'                  => 'AI & Technology',
                'reading_time_minutes' => 15,
                'views_count'          => 3420,
                'downloads_count'      => 580,
                'rating'               => 4.95,
                'published_at'         => '2026-02-01T08:30:00Z',
                'image_url'            => 'https://images.unsplash.com/photo-1485827404703-89b55fcc595e?auto=format&fit=crop&w=1200&q=80',
                'author_name'          => 'Prof. Marcus Vance',
                'author_role'          => 'Chair of Educational Artificial Intelligence & Ethics',
                'author_avatar'        => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=200&q=80',
                'summary'              => 'Generative AI and physical AI hardware (vision kits, neural network accelerator boards) are reshaping classroom pedagogy. This procurement guide details ethical privacy safeguards, COPPA/FERPA data compliance, local edge compute versus cloud AI, and teacher support models.',
                'sections'             => [
                    [
                        'id'      => 'sec-edge-vs-cloud',
                        'title'   => '1. Edge Hardware vs Cloud AI in Schools',
                        'content' => 'For hands-on student experimentation in K-12, prioritize edge neural accelerator boards (such as Google Coral, Raspberry Pi AI Kit, or Jetson Nano) that run object detection and voice recognition locally on device without sending student video or audio to external servers.',
                        'bullet_points' => [
                            'Zero data transmission risks when inferencing runs completely on local edge NPU hardware.',
                            'No ongoing API token fees or cloud subscription lock-in.',
                            'Real-time low-latency response (<20ms) for robotics vision tasks.',
                        ],
                    ],
                    [
                        'id'      => 'sec-data-privacy',
                        'title'   => '2. Data Governance & Student Privacy Compliance',
                        'content' => 'Procurement committees must ensure all software platforms include signed Student Privacy Pledge agreements, explicit prohibition of training LLM foundation models on student inputs, and strict role-based access control with Single Sign-On (SAML/Google Workspace).',
                        'bullet_points' => [
                            'FERPA, COPPA, and GDPR-K compliance certificates required from suppliers.',
                            'Zero data retention policy for prompt interactions.',
                            'Granular administrative dashboard with audit logs.',
                        ],
                    ],
                ],
                'checklists'           => [
                    ['item' => 'Edge AI microcontroller kits with camera modules', 'category' => 'Hardware', 'mandatory' => true],
                    ['item' => 'FERPA & COPPA compliant institutional software agreements', 'category' => 'Compliance', 'mandatory' => true],
                    ['item' => 'Teacher professional training workshops curriculum', 'category' => 'Training', 'mandatory' => true],
                ],
                'downloadable_assets'  => [
                    [
                        'title'       => 'School AI Procurement Policy & Ethical Framework',
                        'file_format' => 'PDF',
                        'file_size'   => '2.8 MB',
                        'description' => 'Model institutional policy template ready for school boards and IT directors.',
                    ],
                ],
                'related_resources'    => [
                    [
                        'id'        => 1,
                        'title'     => 'How to Build a Future-Ready STEM Lab',
                        'tag'       => 'STEM Education',
                        'image_url' => 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=600&q=80',
                    ],
                ],
            ],
        ];

        $guide = $guides[$id] ?? $guides[1];

        return response()->json([
            'data' => $guide,
        ]);
    }

    public function savedItems(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $account = $this->currentAccount($request);

        $type = in_array($request->input('type'), ['supplier', 'listing', 'rfq', 'quotation'], true)
            ? $request->input('type')
            : 'listing';

        $counts = [
            'listing'   => SavedItem::where('account_id', $account->id)->where('item_type', 'listing')->count(),
            'supplier'  => SavedItem::where('account_id', $account->id)->where('item_type', 'supplier')->count(),
            'rfq'       => SavedItem::where('account_id', $account->id)->where('item_type', 'rfq')->count(),
            'quotation' => SavedItem::where('account_id', $account->id)->where('item_type', 'quotation')->count(),
        ];

        $savedItemIds = SavedItem::where('account_id', $account->id)
            ->where('item_type', $type)
            ->latest()
            ->pluck('item_id');

        $items = match ($type) {
            'supplier'  => Account::with(['supplierProfile.country', 'supplierProfile.city'])->whereIn('id', $savedItemIds)->get(),
            'listing'   => Listing::with(['supplierAccount.supplierProfile', 'mainCategory', 'media'])->whereIn('id', $savedItemIds)->get(),
            'rfq'       => Rfq::with('category')->whereIn('id', $savedItemIds)->get(),
            'quotation' => Quotation::with(['rfq', 'supplierAccount.supplierProfile'])->whereIn('id', $savedItemIds)->get(),
        };

        $items = $items->sortBy(fn ($item) => array_search($item->id, $savedItemIds->all()))->values();

        $formatted = match ($type) {
            'supplier' => $items->map(function ($s) use ($request) {
                $profile = $s->supplierProfile;
                $logoUrl = null;
                if ($profile?->logo) {
                    $logoUrl = $request->schemeAndHttpHost() . '/storage/' . ltrim($profile->logo, '/');
                }
                return [
                    'id'            => $s->id,
                    'item_type'     => 'supplier',
                    'title'         => $profile?->display_name ?? $s->display_name ?? 'Certified Supplier',
                    'supplier_name' => $profile?->company_name ?? $profile?->display_name ?? 'Certified Supplier',
                    'country_name'  => $profile?->country?->name ?? 'United States',
                    'city_name'     => $profile?->city?->name,
                    'rating'        => (float) ($profile?->rating ?? 4.9),
                    'review_count'  => (int) ($profile?->reviews_count ?? 18),
                    'logo_url'      => $logoUrl,
                    'category_name' => 'Certified Educational Supplier',
                    'status'        => $s->status ?? 'active',
                ];
            }),
            'listing' => $items->map(function ($l) use ($request) {
                $mediaItem = $l->media->first(function ($m) {
                    return str_starts_with($m->mime_type ?? '', 'image/');
                }) ?? $l->media->first();

                $imageUrl = null;
                if ($mediaItem && !str_ends_with(strtolower($mediaItem->file_name ?? ''), '.mp4')) {
                    $url = $mediaItem->getUrl();
                    $parsed = parse_url($url);
                    $path = $parsed['path'] ?? '';
                    $imageUrl = $request->schemeAndHttpHost() . $path;
                }

                return [
                    'id'            => $l->id,
                    'item_type'     => 'listing',
                    'title'         => $l->name,
                    'supplier_name' => $l->supplierAccount?->supplierProfile?->display_name ?? 'Educational Supplier',
                    'price'         => $l->base_price ? (float) $l->base_price : null,
                    'currency_code' => $l->currency_code ?? 'USD',
                    'is_quote_only' => empty($l->base_price),
                    'image_url'     => $imageUrl,
                    'category_name' => $l->mainCategory?->name ?? 'Academic Products',
                    'status'        => $l->approval_status ?? 'published',
                ];
            }),
            'rfq' => $items->map(function ($r) {
                return [
                    'id'            => $r->id,
                    'item_type'     => 'rfq',
                    'title'         => $r->title,
                    'rfq_number'    => $r->rfq_number ?? ('RFQ-' . $r->id),
                    'category_name' => $r->category?->name ?? 'Institutional Procurement',
                    'status'        => $r->status ?? 'open',
                    'deadline'      => $r->deadline_at?->format('M d, Y') ?? $r->deadline?->format('M d, Y') ?? 'Ongoing',
                ];
            }),
            'quotation' => $items->map(function ($q) {
                return [
                    'id'            => $q->id,
                    'item_type'     => 'quotation',
                    'title'         => $q->rfq?->title ?? ('Quotation #' . $q->id),
                    'supplier_name' => $q->supplierAccount?->supplierProfile?->display_name ?? 'Bidding Supplier',
                    'price'         => (float) ($q->grand_total ?? 0),
                    'currency_code' => $q->currency_code ?? 'USD',
                    'status'        => $q->status ?? 'submitted',
                    'rfq_id'        => $q->rfq_id,
                ];
            }),
        };

        return response()->json([
            'type'   => $type,
            'counts' => $counts,
            'data'   => $formatted->values(),
        ]);
    }

    public function toggleSavedItem(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $account = $this->currentAccount($request);

        $validated = $request->validate([
            'type'   => 'nullable|string|in:supplier,listing,rfq,quotation',
            'id'     => 'nullable|integer',
            'action' => 'nullable|string|in:save,remove,toggle',
        ]);

        $type = $validated['type'] ?? $request->input('item_type') ?? 'listing';
        $id = $validated['id'] ?? $request->input('item_id') ?? $request->input('listing_id');
        $action = $validated['action'] ?? 'toggle';

        if (!$id) {
            return response()->json(['message' => 'Item ID required.'], 422);
        }

        $service = app(\App\Services\SavedItemService::class);
        $isAlreadySaved = $service->isSaved($account, $type, (int) $id);

        if ($action === 'save') {
            $service->save($account, $user, $type, (int) $id);
            $isSaved = true;
            $msg = 'Item bookmarked to saved items.';
        } elseif ($action === 'remove') {
            $service->remove($account, $type, (int) $id);
            $isSaved = false;
            $msg = 'Item removed from saved items.';
        } else {
            $isSaved = $service->toggle($account, $user, $type, (int) $id);
            $msg = $isSaved ? 'Item added to saved items.' : 'Item removed from saved items.';
        }

        $counts = [
            'listing'   => SavedItem::where('account_id', $account->id)->where('item_type', 'listing')->count(),
            'supplier'  => SavedItem::where('account_id', $account->id)->where('item_type', 'supplier')->count(),
            'rfq'       => SavedItem::where('account_id', $account->id)->where('item_type', 'rfq')->count(),
            'quotation' => SavedItem::where('account_id', $account->id)->where('item_type', 'quotation')->count(),
        ];

        return response()->json([
            'message'  => $msg,
            'is_saved' => $isSaved,
            'type'     => $type,
            'id'       => (int) $id,
            'counts'   => $counts,
        ]);
    }

    public function privacyPolicy(): JsonResponse
    {
        $sections = [
            [
                'title' => 'Information We Collect',
                'body'  => 'We collect account information you provide during registration, profile and company details, RFQ and quotation content, and inquiry or contact form submissions. We also collect standard technical data such as IP address and browser information for security purposes.',
            ],
            [
                'title' => 'How We Use Information',
                'body'  => 'Information is used to operate the marketplace, facilitate RFQ and quotation workflows, verify Supplier eligibility, respond to inquiries, and maintain platform security.',
            ],
            [
                'title' => 'Public Information',
                'body'  => 'Certain information — such as published listings, Supplier storefront details, published reviews and global RFQ summaries — is intentionally public. Private information, including RFQ buyer contact details, selected-Supplier RFQ data and Supplier verification documents, is not exposed publicly.',
            ],
            [
                'title' => 'Guest Inquiries',
                'body'  => 'When you submit a Contact Supplier or Contact Us form, we store your submitted details and route them to the relevant Supplier or our support team as a lead — not as an RFQ or official procurement request.',
            ],
            [
                'title' => 'Data Sharing',
                'body'  => 'We do not sell personal information. Information is shared with other users only as needed for marketplace functionality, for example sharing your inquiry with the Supplier you contacted.',
            ],
            [
                'title' => 'Your Choices & Rights',
                'body'  => 'You may update your account information at any time from your dashboard, or contact us to request assistance with your data.',
            ],
            [
                'title' => 'Contact Us',
                'body'  => 'For privacy questions or requests, contact our support team at support@edushopify.com or through the Help Center in your account settings.',
            ],
        ];

        return response()->json([
            'data' => [
                'title'      => 'Privacy Policy',
                'updated_at' => 'October 27, 2026',
                'intro'      => 'This Privacy Policy explains how Edushopify collects, uses and protects information when you use the marketplace.',
                'sections'   => $sections,
            ],
        ]);
    }

    public function termsPolicy(): JsonResponse
    {
        $sections = [
            [
                'title' => 'The Marketplace',
                'body'  => 'Edushopify is a B2B education procurement marketplace connecting institutional Buyers with verified Suppliers through a structured Request for Quotation (RFQ) process. Edushopify facilitates discovery, sourcing and communication between Buyers and Suppliers.',
            ],
            [
                'title' => 'Accounts',
                'body'  => 'You must provide accurate information when registering. Buyer and Supplier capabilities are subject to eligibility, approval and ongoing compliance with these terms. You are responsible for activity carried out under your account and its team members.',
            ],
            [
                'title' => 'RFQs, Quotations and Purchase Orders',
                'body'  => 'An RFQ posted by a Buyer is a structured sourcing request. A guest inquiry submitted through the public marketplace is not an RFQ, quotation, award or purchase order. Official procurement actions require an authenticated, eligible account.',
            ],
            [
                'title' => 'Subscriptions',
                'body'  => 'Suppliers access marketplace features through a subscription plan. Plan limits, pricing and billing periods are shown on the Pricing page and in the supplier dashboard at the time of purchase.',
            ],
            [
                'title' => 'Fulfilment & Payment',
                'body'  => 'Payment and fulfilment for products and services currently take place outside Edushopify, directly between the Buyer and Supplier. Edushopify processes Supplier subscription payments only.',
            ],
            [
                'title' => 'Account Closure & Deletion Policy',
                'body'  => 'You may request closure of your account from Settings & Account. Outstanding obligations such as open RFQs, pending awards, active purchase orders, or open support tickets must be resolved first. Transactional history (RFQs, quotations, awards, purchase orders, reviews, and support tickets) is preserved for audit and legal compliance.',
            ],
            [
                'title' => 'Conduct',
                'body'  => 'Users must not misuse the platform, submit false information, or attempt to access data or accounts they are not authorized to access. We may suspend accounts that breach these terms.',
            ],
            [
                'title' => 'Changes to These Terms',
                'body'  => 'We may update these terms from time to time. Continued use of the platform after changes are published constitutes acceptance of the revised terms.',
            ],
        ];

        return response()->json([
            'data' => [
                'title'      => 'Terms & Policy',
                'updated_at' => 'October 27, 2026',
                'intro'      => 'These Terms of Service govern access to and use of the Edushopify marketplace. By creating an account or using the platform, you agree to these terms.',
                'sections'   => $sections,
            ],
        ]);
    }

    public function faqs(): JsonResponse
    {
        $faqGroups = [
            [
                'category' => 'Buyer Registration & Account',
                'items' => [
                    [
                        'question' => 'How do I register as an Institutional Buyer?',
                        'answer'   => 'Click Join Free or Register, select the Buyer capability, and complete your institution account and profile details.',
                    ],
                    [
                        'question' => 'Is registration free for Buyers?',
                        'answer'   => 'Yes, creating a Buyer account, searching certified suppliers, and publishing RFQs is completely free.',
                    ],
                ],
            ],
            [
                'category' => 'RFQs & Quotations',
                'items' => [
                    [
                        'question' => 'How do I create a Request for Quotation (RFQ)?',
                        'answer'   => 'Go to Procurement > Create RFQ, define your line items, specify a quotation deadline, choose Global or Selected Suppliers, and publish.',
                    ],
                    [
                        'question' => 'How do I review and award quotations?',
                        'answer'   => 'Open your published RFQ, compare received supplier quotes side by side, shortlist preferred bids, and tap Award to issue an official award notice.',
                    ],
                    [
                        'question' => 'What happens after a supplier accepts an award?',
                        'answer'   => 'A formal Purchase Order (PO) is automatically generated from the accepted quotation terms. You can track fulfilment under Procurement > Orders.',
                    ],
                ],
            ],
            [
                'category' => 'Orders & Direct Payments',
                'items' => [
                    [
                        'question' => 'How are product payments settled?',
                        'answer'   => 'Payments for awarded purchase orders are settled directly between your institution and the supplier according to agreed terms (e.g. Net 30, Wire, Card). The mobile app tracks order delivery and milestone status.',
                    ],
                    [
                        'question' => 'How do I update delivery locations?',
                        'answer'   => 'Go to Profile > Delivery Locations to add, edit, or set default delivery warehouses, campus receiving docks, and central logistics addresses.',
                    ],
                ],
            ],
            [
                'category' => 'Support & Disputes',
                'items' => [
                    [
                        'question' => 'How do I raise a dispute or report an order issue?',
                        'answer'   => 'Go to the Help & Support Desk and tap Open Ticket. Specify the category (Procurement Help, Supplier Dispute, or Technical Support) and your request will be prioritized by our support team.',
                    ],
                    [
                        'question' => 'Is my RFQ data private and secure?',
                        'answer'   => 'Selected-Supplier RFQs are visible only to the suppliers you invite. Global RFQs only publish safe specifications while protecting sensitive contact details until quotations are received.',
                    ],
                ],
            ],
        ];

        return response()->json(['data' => $faqGroups]);
    }

    public function contactInfo(): JsonResponse
    {
        return response()->json([
            'data' => [
                'email'         => config('contact.email', 'support@edushopify.com'),
                'phone'         => '+1 (800) 555-0199',
                'response_time' => '1–2 business days',
                'hours'         => 'Monday – Friday, 8am – 6pm EST',
                'address'       => '100 University Avenue, Suite 400, Austin, TX 78712',
            ],
        ]);
    }

    public function submitContact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:150',
            'email'        => 'required|email|max:190',
            'phone'        => 'nullable|string|max:30',
            'organization' => 'nullable|string|max:150',
            'subject'      => 'required|string|max:190',
            'message'      => 'required|string|max:3000',
        ]);

        $number = 'INQ-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

        ContactInquiry::create([
            'inquiry_number' => $number,
            'name'           => $validated['name'],
            'email'          => $validated['email'],
            'phone'          => $validated['phone'] ?? null,
            'organization'   => $validated['organization'] ?? null,
            'subject'        => $validated['subject'],
            'message'        => $validated['message'],
            'status'         => 'new',
            'ip_address'     => $request->ip(),
            'user_agent'     => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json([
            'message'        => 'Thanks — your message has been sent. Our team will get back to you soon.',
            'inquiry_number' => $number,
        ]);
    }

    public function aboutUs(): JsonResponse
    {
        $supplierAccountIds = PublicSupplierQuery::base()->select('account_id');

        $stats = [
            ['value' => (string) PublicSupplierQuery::base()->count(), 'label' => 'Verified Suppliers'],
            ['value' => (string) PublicListingQuery::base()->count(), 'label' => 'Products & Services'],
            ['value' => (string) Rfq::globalVisibility()->open()->count(), 'label' => 'Open RFQs'],
            ['value' => (string) AccountLocation::where('is_active', true)
                ->whereIn('account_id', $supplierAccountIds)
                ->distinct()
                ->count('country_id'), 'label' => 'Countries'],
        ];

        return response()->json([
            'data' => [
                'title'    => "Connecting the World's Education Buyers with Trusted Suppliers",
                'subtitle' => 'Edushopify is a B2B procurement marketplace built for schools, universities and training institutions — making it simple to discover verified suppliers, request quotations and compare offers in one place.',
                'mission'  => 'To make education procurement transparent and efficient — giving institutional buyers a structured way to source what they need, and giving suppliers a fair place to compete on quality and value.',
                'vision'   => 'A connected global education marketplace where every institution, regardless of size or location, can reach trusted suppliers and every learner benefits from better resources.',
                'stats'    => $stats,
                'values'   => [
                    [
                        'title' => 'Trust',
                        'text'  => 'Suppliers are reviewed and approved by our team before they are marked as verified on the marketplace.',
                        'icon'  => 'shield',
                    ],
                    [
                        'title' => 'Transparency',
                        'text'  => 'Structured RFQs and side-by-side quotation comparison help buyers make clear, well-documented decisions.',
                        'icon'  => 'balance',
                    ],
                    [
                        'title' => 'Access',
                        'text'  => 'Institutions of every size can reach suppliers across countries without long, informal sourcing chains.',
                        'icon'  => 'globe',
                    ],
                ],
            ],
        ]);
    }
}
