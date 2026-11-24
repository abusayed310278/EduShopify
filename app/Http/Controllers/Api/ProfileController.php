<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithApiBuyer;
use App\Http\Controllers\Controller;
use App\Models\AccountLocation;
use App\Models\BuyerGallery;
use App\Models\BuyerProfile;
use App\Models\BuyerType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\SocialPlatform;
use App\Services\BuyerProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    use InteractsWithApiBuyer;

    public function getProfile(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $account->load([
            'buyerProfile.country', 'buyerProfile.state', 'buyerProfile.city',
            'buyerTypes',
            'buyerGalleryImages',
            'socialLinks.platform',
            'locations',
        ]);

        $profile = $account->buyerProfile ?? BuyerProfile::firstOrCreate(['account_id' => $account->id], [
            'display_name'      => $account->display_name ?? $user->name,
            'organization_name' => $account->display_name,
            'contact_person'    => $user->name,
            'email'             => $user->email,
        ]);

        $schemeAndHost = $request->schemeAndHttpHost();

        $logoUrl = $profile->logo ? $schemeAndHost . '/storage/' . ltrim($profile->logo, '/') : null;
        $photoUrl = $profile->profile_photo ? $schemeAndHost . '/storage/' . ltrim($profile->profile_photo, '/') : null;

        $availableBuyerTypes = BuyerType::active()->get()->map(fn ($bt) => [
            'id'   => $bt->id,
            'name' => $bt->name,
        ]);

        $selectedBuyerTypeIds = $account->buyerTypes->pluck('id')->values()->all();
        if (empty($selectedBuyerTypeIds) && $profile->buyer_type_id) {
            $selectedBuyerTypeIds = [$profile->buyer_type_id];
        }

        $socialPlatforms = SocialPlatform::all()->map(fn ($sp) => [
            'id'   => $sp->id,
            'name' => $sp->name,
        ]);

        $socialLinks = $account->socialLinks->map(fn ($sl) => [
            'id'            => $sl->id,
            'platform_id'   => $sl->social_platform_id,
            'platform_name' => $sl->platform?->name ?? 'Other',
            'url'           => $sl->url ?? '',
            'label'         => $sl->label ?? '',
        ]);

        $gallery = $account->buyerGalleryImages->map(fn ($img) => [
            'id'        => $img->id,
            'image_url' => $schemeAndHost . '/storage/' . ltrim($img->image_path, '/'),
        ]);

        return response()->json([
            'data' => [
                'id'                   => $profile->id,
                'account_id'           => $account->id,
                'display_name'         => $profile->display_name ?? $account->display_name ?? 'Buyer Account',
                'organization_name'    => $profile->organization_name ?? '',
                'contact_person'       => $profile->contact_person ?? $user->name,
                'position'             => $profile->position ?? '',
                'email'                => $profile->email ?? $user->email,
                'phone'                => $profile->phone ?? '',
                'website'              => $profile->website ?? '',
                'tax_id'               => $profile->tax_id ?? '',
                'bio'                  => $profile->bio ?? '',
                'procurement_info'     => $profile->procurement_info ?? '',
                'logo_url'             => $logoUrl,
                'profile_photo_url'    => $photoUrl,
                'country_id'           => $profile->country_id,
                'country_name'         => $profile->country?->name ?? 'United States',
                'city_name'            => $profile->city?->name,
                'address'              => $profile->address ?? '',
                'buyer_type_ids'       => $selectedBuyerTypeIds,
                'available_buyer_types'=> $availableBuyerTypes,
                'social_platforms'     => $socialPlatforms,
                'social_links'         => $socialLinks,
                'gallery'              => $gallery,
                'stats'                => [
                    'gallery_count'   => $gallery->count(),
                    'locations_count' => $account->locations->count(),
                ],
                // Legacy support
                'company_name'               => $profile->organization_name ?: ($profile->display_name ?: $account->display_name),
                'buyer_type'                 => $account->buyerTypes->first()?->name ?? 'Educational Institution',
                'annual_procurement_budget'  => 125000.00,
                'preferred_payment_terms'    => 'Net 30 Days',
                'registration_number'        => $profile->tax_id,
                'tax_number'                 => $profile->tax_id,
                'established_year'           => 1998,
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'display_name'      => 'nullable|string|max:150',
            'organization_name' => 'nullable|string|max:150',
            'contact_person'    => 'nullable|string|max:150',
            'position'          => 'nullable|string|max:100',
            'email'             => 'nullable|email|max:150',
            'phone'             => 'nullable|string|max:30',
            'website'           => 'nullable|string|max:255',
            'tax_id'            => 'nullable|string|max:100',
            'bio'               => 'nullable|string',
            'procurement_info'  => 'nullable|string',
            'buyer_type_ids'    => 'nullable|array',
            'buyer_type_ids.*'  => 'integer',
            'social_links'      => 'nullable|array',
            'logo'              => 'nullable',
            'profile_photo'     => 'nullable',
        ]);

        $service = app(BuyerProfileService::class);
        $profile = $service->saveDraft($account, $validated);

        if (!empty($validated['display_name'])) {
            $account->update(['display_name' => $validated['display_name']]);
        }

        if ($request->has('social_links')) {
            $account->socialLinks()->delete();
            foreach ((array) $request->input('social_links') as $link) {
                if (!empty($link['url'])) {
                    $account->socialLinks()->create([
                        'social_platform_id' => $link['platform_id'] ?? 1,
                        'url'                => $link['url'],
                        'label'              => $link['label'] ?? null,
                    ]);
                }
            }
        }

        // Handle logo upload or URL
        if ($request->hasFile('logo')) {
            if ($profile->logo && Storage::disk('public')->exists($profile->logo)) {
                Storage::disk('public')->delete($profile->logo);
            }
            $profile->logo = $request->file('logo')->store('buyers/logos', 'public');
            $profile->save();
        } elseif ($request->has('logo')) {
            $logoVal = $request->input('logo');
            if (is_string($logoVal)) {
                if (str_contains($logoVal, '/storage/')) {
                    $logoVal = substr($logoVal, strpos($logoVal, '/storage/') + 9);
                }
                $profile->logo = $logoVal ?: null;
                $profile->save();
            }
        }

        // Handle profile photo upload or URL
        if ($request->hasFile('profile_photo')) {
            if ($profile->profile_photo && Storage::disk('public')->exists($profile->profile_photo)) {
                Storage::disk('public')->delete($profile->profile_photo);
            }
            $profile->profile_photo = $request->file('profile_photo')->store('buyers/photos', 'public');
            $profile->save();
        } elseif ($request->has('profile_photo')) {
            $photoVal = $request->input('profile_photo');
            if (is_string($photoVal)) {
                if (str_contains($photoVal, '/storage/')) {
                    $photoVal = substr($photoVal, strpos($photoVal, '/storage/') + 9);
                }
                $profile->profile_photo = $photoVal ?: null;
                $profile->save();
            }
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data'    => $this->getProfile($request)->getData()->data,
        ]);
    }

    public function storeGalleryImage(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $request->validate([
            'image'     => 'nullable|image|max:10240',
            'image_url' => 'nullable|string|max:1000',
            'caption'   => 'nullable|string|max:255',
        ]);

        $schemeAndHost = $request->schemeAndHttpHost();
        $path = null;

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('buyers/gallery', 'public');
        } elseif ($request->filled('image_url')) {
            $path = $request->input('image_url');
        }

        if (! $path) {
            return response()->json(['message' => 'Image file or valid image URL is required.'], 422);
        }

        $gallery = BuyerGallery::create([
            'buyer_account_id'   => $account->id,
            'image_path'         => $path,
            'caption'            => $request->input('caption'),
            'is_active'          => true,
            'created_by_user_id' => $user->id,
        ]);

        $fullUrl = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : $schemeAndHost . '/storage/' . ltrim($path, '/');

        return response()->json([
            'message' => 'Gallery image added successfully.',
            'data'    => [
                'id'        => $gallery->id,
                'image_url' => $fullUrl,
                'caption'   => $gallery->caption,
            ],
        ], 201);
    }

    public function deleteGalleryImage(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);

        $image = BuyerGallery::where('buyer_account_id', $account->id)->find($id);
        if ($image) {
            if ($image->image_path && !str_starts_with($image->image_path, 'http')) {
                Storage::disk('public')->delete($image->image_path);
            }
            $image->delete();
            return response()->json(['message' => 'Gallery image deleted successfully.']);
        }

        return response()->json(['message' => 'Gallery image not found.'], 404);
    }

    public function locations(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $locations = AccountLocation::with(['city', 'state', 'country'])
            ->where('account_id', $account->id)
            ->get();

        if ($locations->isEmpty()) {
            $country = Country::first();
            $loc = AccountLocation::create([
                'account_id'         => $account->id,
                'created_by_user_id' => $user->id,
                'location_type'      => 'delivery',
                'country_id'         => $country?->id ?? 1,
                'label'              => 'Main Campus Central Receiving Dock',
                'address_line_1'     => 'Building B, 100 University Avenue',
                'postal_code'        => '78712',
                'is_primary'         => true,
            ]);
            $locations = collect([$loc]);
        }

        $data = $locations->map(fn ($l) => $this->formatLocation($l));

        return response()->json(['data' => $data]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'name'           => 'nullable|string|max:255',
            'label'          => 'nullable|string|max:255',
            'address_line_1' => 'required|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city_name'      => 'nullable|string|max:255',
            'state_name'     => 'nullable|string|max:255',
            'postal_code'    => 'nullable|string|max:20',
            'is_primary'     => 'nullable|boolean',
        ]);

        $country = Country::first();

        $loc = AccountLocation::create([
            'account_id'         => $account->id,
            'created_by_user_id' => $user->id,
            'location_type'      => 'delivery',
            'country_id'         => $country?->id ?? 1,
            'label'              => $validated['name'] ?? $validated['label'] ?? 'School Delivery Point',
            'address_line_1'     => $validated['address_line_1'],
            'address_line_2'     => $validated['address_line_2'] ?? null,
            'postal_code'        => $validated['postal_code'] ?? '78701',
            'is_primary'         => (bool) ($validated['is_primary'] ?? false),
        ]);

        return response()->json([
            'message' => 'Delivery location created successfully.',
            'data'    => $this->formatLocation($loc),
        ], 201);
    }

    public function deleteLocation(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        AccountLocation::where('account_id', $account->id)->where('id', $id)->delete();

        return response()->json(['message' => 'Location deleted successfully.']);
    }

    public function makePrimaryLocation(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);

        AccountLocation::where('account_id', $account->id)->update(['is_primary' => false]);
        AccountLocation::where('account_id', $account->id)->where('id', $id)->update(['is_primary' => true]);

        return response()->json(['message' => 'Primary delivery point updated.']);
    }

    private function formatLocation(AccountLocation $l): array
    {
        return [
            'id'             => $l->id,
            'name'           => $l->label ?? 'Campus Delivery Point',
            'address_line_1' => $l->address_line_1,
            'address_line_2' => $l->address_line_2,
            'city_name'      => $l->city?->name ?? 'Austin',
            'state_name'     => $l->state?->name ?? 'TX',
            'country_name'   => $l->country?->name ?? 'United States',
            'postal_code'    => $l->postal_code ?? '78701',
            'is_primary'     => (bool) ($l->is_primary ?? false),
        ];
    }

    public function closureCheck(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $blockers = collect();

        if ($account->rfqs()->whereIn('status', ['open', 'award_pending'])->exists()) {
            $blockers->push('Open RFQs pending award or closure');
        }
        if ($account->buyerAwards()->where('status', 'pending_supplier_response')->exists()) {
            $blockers->push('Pending supplier quotation awards');
        }
        if ($account->buyerPurchaseOrders()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            $blockers->push('Active purchase orders in progress');
        }
        if ($account->tickets()->whereNotIn('status', ['resolved', 'closed'])->exists()) {
            $blockers->push('Open support tickets');
        }

        return response()->json([
            'data' => [
                'can_close' => $blockers->isEmpty(),
                'status'    => $account->status,
                'blockers'  => $blockers->values(),
            ],
        ]);
    }

    public function requestClosure(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $blockers = collect();
        if ($account->rfqs()->whereIn('status', ['open', 'award_pending'])->exists()) {
            $blockers->push('Open RFQs pending award or closure');
        }
        if ($account->buyerAwards()->where('status', 'pending_supplier_response')->exists()) {
            $blockers->push('Pending supplier quotation awards');
        }
        if ($account->buyerPurchaseOrders()->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
            $blockers->push('Active purchase orders in progress');
        }
        if ($account->tickets()->whereNotIn('status', ['resolved', 'closed'])->exists()) {
            $blockers->push('Open support tickets');
        }

        if ($blockers->isNotEmpty()) {
            return response()->json([
                'message'  => 'Resolve outstanding obligations before closing your account.',
                'blockers' => $blockers->values(),
            ], 422);
        }

        $account->update([
            'status'                => 'deletion_pending',
            'deletion_requested_at' => now(),
            'deletion_reason'       => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Your account closure request has been submitted for administrative review.',
        ]);
    }

    public function getSecurity(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $currencies = Currency::where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name', 'symbol']);

        if ($currencies->isEmpty()) {
            $currencies = collect([
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$'],
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€'],
                ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£'],
                ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'CA$'],
                ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$'],
            ]);
        }

        return response()->json([
            'data' => [
                'name'          => $user->name,
                'email'         => $user->email,
                'phone'         => $user->phone,
                'currency_code' => $user->currency_code ?? 'USD',
                'locale'        => $user->locale ?? 'en',
            ],
            'currencies' => $currencies,
        ]);
    }

    public function updateSecurity(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'name'                  => 'required|string|max:150',
            'phone'                 => 'nullable|string|max:30',
            'currency_code'         => 'nullable|string|size:3',
            'current_password'      => 'nullable|string|required_with:password',
            'password'              => 'nullable|string|min:8|confirmed',
        ]);

        if (!empty($validated['password'])) {
            if (!Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'message' => 'The current password provided does not match our records.',
                    'errors'  => [
                        'current_password' => ['The current password provided is incorrect.'],
                    ],
                ], 422);
            }
            $user->password = Hash::make($validated['password']);
        }

        $user->name = $validated['name'];
        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'];
        }
        if (array_key_exists('currency_code', $validated)) {
            $user->currency_code = $validated['currency_code'];
        }
        $user->save();

        return response()->json([
            'message' => 'Personal details and security settings updated successfully.',
            'data'    => [
                'name'          => $user->name,
                'email'         => $user->email,
                'phone'         => $user->phone,
                'currency_code' => $user->currency_code,
            ],
        ]);
    }
}
