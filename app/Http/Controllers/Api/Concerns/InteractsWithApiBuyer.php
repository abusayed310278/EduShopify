<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

trait InteractsWithApiBuyer
{
    /**
     * Resolve the current authenticated user (with fallback to default seeded demo buyer).
     */
    protected function currentUser(Request $request): User
    {
        $bearer = $request->bearerToken();
        if ($bearer) {
            $userId = Cache::get('api_token_' . $bearer);
            if ($userId && $cachedUser = User::find($userId)) {
                Auth::setUser($cachedUser);
                return $cachedUser;
            }
        }

        $headerAccountId = $request->header('X-Account-Id');
        if ($headerAccountId) {
            $account = Account::find($headerAccountId);
            if ($account && $account->primaryOwner) {
                Auth::setUser($account->primaryOwner);
                return $account->primaryOwner;
            }
        }

        $user = Auth::user();

        if (! $user) {
            $user = User::where('email', 'buyer@school.edu')->first() ?? User::first();
            if ($user) {
                Auth::setUser($user);
            }
        }

        return $user;
    }

    /**
     * Resolve the active buyer account context respecting the X-Account-Id header.
     */
    protected function currentAccount(Request $request): Account
    {
        $headerAccountId = $request->header('X-Account-Id');

        if ($headerAccountId && $account = Account::find($headerAccountId)) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($account->id);
            return $account;
        }

        $user = $this->currentUser($request);

        $account = $user->accountMember?->account
            ?? Account::whereHas('capabilities', function ($q) {
                $q->whereHas('capabilityType', fn ($ct) => $ct->where('code', 'buyer'));
            })->first()
            ?? Account::first();

        if ($account) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($account->id);
        }

        return $account;
    }
}
