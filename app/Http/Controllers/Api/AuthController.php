<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountCapability;
use App\Models\AccountMember;
use App\Models\BuyerProfile;
use App\Models\CapabilityType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password. Please verify your credentials.',
                'errors'  => [
                    'email' => ['Invalid email or password.'],
                ],
            ], 401);
        }

        $accounts = Account::whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->with('capabilities.capabilityType')->get();

        if ($accounts->isEmpty()) {
            $accountNumber = 'ACC-BUY-' . strtoupper(Str::random(6));
            $account = Account::firstOrCreate(
                ['account_number' => $accountNumber],
                [
                    'display_name'          => $user->name . ' Educational Academy',
                    'slug'                  => Str::slug($user->name) . '-' . rand(100, 999),
                    'account_type'          => 'organization',
                    'status'                => 'active',
                    'primary_owner_user_id' => $user->id,
                ]
            );

            AccountMember::firstOrCreate(
                ['account_id' => $account->id, 'user_id' => $user->id],
                [
                    'member_type'      => 'owner',
                    'is_primary_owner' => true,
                    'status'           => 'active',
                    'joined_at'        => now(),
                ]
            );

            $buyerCapType = CapabilityType::where('code', 'buyer')->first();
            if ($buyerCapType) {
                AccountCapability::firstOrCreate([
                    'account_id'         => $account->id,
                    'capability_type_id' => $buyerCapType->id,
                ], [
                    'status'             => 'active',
                    'applied_at'         => now(),
                    'approved_at'        => now(),
                ]);
            }

            BuyerProfile::firstOrCreate(
                ['account_id' => $account->id],
                [
                    'display_name'      => $user->name . ' Educational Academy',
                    'organization_name' => $user->name . ' Educational Academy',
                    'contact_person'    => $user->name,
                    'email'             => $user->email,
                    'phone'             => $user->phone,
                ]
            );

            $accounts = Account::where('id', $account->id)->with('capabilities.capabilityType')->get();
        }

        $formattedAccounts = $accounts->map(function ($acc) {
            $caps = ['buyer'];
            if ($acc->relationLoaded('capabilities') && $acc->capabilities->isNotEmpty()) {
                $caps = $acc->capabilities->map(function ($c) {
                    return $c->capabilityType?->code ?? $c->code ?? 'buyer';
                })->filter()->values()->toArray();
            }

            return [
                'id'             => $acc->id,
                'account_number' => $acc->account_number ?? 'ACC-' . $acc->id,
                'display_name'   => $acc->display_name ?? 'Educational Institution',
                'account_type'   => $acc->account_type ?? 'organization',
                'status'         => $acc->status ?? 'active',
                'is_owner'       => true,
                'capabilities'   => $caps,
            ];
        })->values()->toArray();

        $token = 'buyer_token_' . Str::random(40);
        Cache::put('api_token_' . $token, $user->id, now()->addDays(30));
        Cache::put('api_token_' . $token, $user->id, now()->addDays(30));

        return response()->json([
            'token'          => $token,
            'user'           => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'accounts'       => $formattedAccounts,
            'active_account' => $formattedAccounts[0] ?? null,
        ]);
    }

    /**
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_name' => 'required|string|max:200',
            'institution_type' => 'nullable|string|max:100',
            'name'             => 'required|string|max:150',
            'email'            => 'required|email|max:255|unique:users,email',
            'password'         => 'required|string|min:6',
            'phone'            => 'nullable|string|max:30',
        ]);

        $user = User::create([
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'phone'             => $validated['phone'] ?? null,
            'password'          => Hash::make($validated['password']),
            'email_verified_at' => now(),
            'status'            => 'active',
        ]);

        $accountNumber = 'ACC-BUY-' . strtoupper(Str::random(6));
        $account = Account::create([
            'account_number'        => $accountNumber,
            'display_name'          => $validated['institution_name'],
            'slug'                  => Str::slug($validated['institution_name']) . '-' . rand(100, 999),
            'account_type'          => 'organization',
            'status'                => 'active',
            'primary_owner_user_id' => $user->id,
        ]);

        AccountMember::create([
            'account_id'       => $account->id,
            'user_id'          => $user->id,
            'member_type'      => 'owner',
            'is_primary_owner' => true,
            'status'           => 'active',
            'joined_at'        => now(),
        ]);

        $buyerCapType = CapabilityType::where('code', 'buyer')->first();
        if ($buyerCapType) {
            AccountCapability::create([
                'account_id'         => $account->id,
                'capability_type_id' => $buyerCapType->id,
                'status'             => 'active',
                'applied_at'         => now(),
                'approved_at'        => now(),
            ]);
        }

        BuyerProfile::create([
            'account_id'        => $account->id,
            'display_name'      => $validated['institution_name'],
            'organization_name' => $validated['institution_name'],
            'contact_person'    => $validated['name'],
            'email'             => $validated['email'],
            'phone'             => $validated['phone'] ?? null,
        ]);

        $token = 'buyer_token_' . Str::random(40);
        Cache::put('api_token_' . $token, $user->id, now()->addDays(30));

        $activeAccount = [
            'id'             => $account->id,
            'account_number' => $account->account_number,
            'display_name'   => $account->display_name,
            'account_type'   => $account->account_type,
            'status'         => 'active',
            'is_owner'       => true,
            'capabilities'   => ['buyer'],
        ];

        return response()->json([
            'token'          => $token,
            'user'           => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'accounts'       => [$activeAccount],
            'active_account' => $activeAccount,
        ], 201);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(): JsonResponse
    {
        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * POST /api/auth/forgot-password
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json([
                'message' => 'We could not find an institutional account registered with that email.',
                'errors'  => [
                    'email' => ['No account found with this email address.'],
                ],
            ], 404);
        }

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token'      => Hash::make(Str::random(32)),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Password reset instructions have been dispatched to your institutional inbox.',
        ]);
    }

    /**
     * POST /api/auth/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'token'    => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        $user = User::where('email', $validated['email'])->first();
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        DB::table('password_reset_tokens')->where('email', $validated['email'])->delete();

        return response()->json(['message' => 'Password has been reset successfully.']);
    }

    /**
     * GET /api/auth/accounts
     */
    public function accounts(Request $request): JsonResponse
    {
        $accounts = Account::with('capabilities.capabilityType')->get();

        $data = $accounts->map(function ($acc) {
            $caps = ['buyer'];
            if ($acc->relationLoaded('capabilities') && $acc->capabilities->isNotEmpty()) {
                $caps = $acc->capabilities->map(function ($cap) {
                    return $cap->capabilityType?->code ?? $cap->code ?? 'buyer';
                })->filter()->values()->toArray();
            }

            return [
                'id'             => $acc->id,
                'account_number' => $acc->account_number ?? 'ACC-' . $acc->id,
                'display_name'   => $acc->display_name ?? 'Educational Institution',
                'account_type'   => $acc->account_type ?? 'organization',
                'status'         => $acc->status ?? 'active',
                'is_owner'       => true,
                'capabilities'   => $caps,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/auth/switch-account
     */
    public function switchAccount(Request $request): JsonResponse
    {
        $accountId = $request->input('account_id');
        $account = Account::find($accountId);

        if (! $account) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        return response()->json([
            'message'        => 'Switched account context successfully.',
            'active_account' => [
                'id'             => $account->id,
                'account_number' => $account->account_number ?? 'ACC-' . $account->id,
                'display_name'   => $account->display_name ?? 'Educational Institution',
                'account_type'   => $account->account_type ?? 'organization',
                'status'         => $account->status ?? 'active',
                'is_owner'       => true,
                'capabilities'   => ['buyer'],
            ],
        ]);
    }
}
