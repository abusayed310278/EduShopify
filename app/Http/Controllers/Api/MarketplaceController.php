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

    public function resources(Request $request): JsonResponse
    {
        $resources = [
            [
                'id'        => 1,
                'title'     => 'How to Build a Future-Ready STEM Lab',
                'subtitle'  => 'Complete planning guide, equipment checklist, and safety standards.',
                'tag'       => 'STEM Education',
                'image_url' => 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=400&q=80',
                'route'     => '/marketplace',
            ],
            [
                'id'        => 2,
                'title'     => 'Interactive Display Buying Guide for Schools',
                'subtitle'  => 'Resolution, touch capability, software licensing, and TCO considerations.',
                'tag'       => 'Smart Classroom',
                'image_url' => 'https://images.unsplash.com/photo-1577896851231-70ef18881754?auto=format&fit=crop&w=400&q=80',
                'route'     => '/marketplace',
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
                'route'     => '/marketplace',
            ],
            [
                'id'        => 5,
                'title'     => 'Bulk Order Discounts & Institutional Savings',
                'subtitle'  => 'Strategies for maximizing your annual institutional procurement budget.',
                'tag'       => 'Cost Optimization',
                'image_url' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=400&auto=format&fit=crop&q=80',
                'route'     => '/marketplace',
            ],
            [
                'id'        => 6,
                'title'     => 'AI in Education: Implementation Guide',
                'subtitle'  => 'Safely introducing artificial intelligence kits into K-12 classrooms.',
                'tag'       => 'AI & Technology',
                'image_url' => 'https://images.unsplash.com/photo-1485827404703-89b55fcc595e?auto=format&fit=crop&w=400&q=80',
                'route'     => '/marketplace',
            ],
        ];

        return response()->json(['data' => $resources]);
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
