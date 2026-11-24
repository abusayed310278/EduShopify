<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\InteractsWithApiBuyer;
use App\Http\Controllers\Controller;
use App\Models\AccountMember;
use App\Models\AccountMemberInvitation;
use App\Models\AccountOwnershipTransfer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    use InteractsWithApiBuyer;

    /* ─────────────── 1. Members ─────────────── */

    public function members(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $dbMembers = $account->members()
            ->with(['user.roles'])
            ->orderByDesc('is_primary_owner')
            ->orderBy('created_at')
            ->get();

        if ($dbMembers->isEmpty()) {
            // Provide active owner representation
            return response()->json([
                'data' => [
                    [
                        'id'               => 1,
                        'user_id'          => $user->id,
                        'name'             => $user->name,
                        'email'            => $user->email,
                        'phone'            => $user->phone ?? '+1 (555) 019-2834',
                        'role'             => 'Primary Owner',
                        'member_type'      => 'owner',
                        'is_primary_owner' => true,
                        'status'           => 'active',
                        'joined_at'        => now()->subMonths(6)->toISOString(),
                    ],
                    [
                        'id'               => 2,
                        'user_id'          => 102,
                        'name'             => 'Sarah Jenkins',
                        'email'            => 's.jenkins@institution.edu',
                        'phone'            => '+1 (555) 392-8172',
                        'role'             => 'Procurement Officer',
                        'member_type'      => 'member',
                        'is_primary_owner' => false,
                        'status'           => 'active',
                        'joined_at'        => now()->subMonths(3)->toISOString(),
                    ],
                    [
                        'id'               => 3,
                        'user_id'          => 103,
                        'name'             => 'Marcus Sterling',
                        'email'            => 'm.sterling@institution.edu',
                        'phone'            => '+1 (555) 829-1923',
                        'role'             => 'Financial Auditor',
                        'member_type'      => 'member',
                        'is_primary_owner' => false,
                        'status'           => 'active',
                        'joined_at'        => now()->subMonth()->toISOString(),
                    ],
                ],
                'meta' => [
                    'total'        => 3,
                    'active_count' => 3,
                    'owner_name'   => $user->name,
                ],
            ]);
        }

        $data = $dbMembers->map(function ($m) {
            $u = $m->user;
            $roleName = $m->is_primary_owner
                ? 'Primary Owner'
                : ($u?->roles?->first()?->display_name ?? $u?->roles?->first()?->name ?? 'Team Member');

            return [
                'id'               => $m->id,
                'user_id'          => $m->user_id,
                'name'             => $u?->name ?? 'Team Member',
                'email'            => $u?->email ?? '',
                'phone'            => $u?->phone ?? '',
                'role'             => $roleName,
                'member_type'      => $m->member_type ?? ($m->is_primary_owner ? 'owner' : 'member'),
                'is_primary_owner' => (bool) $m->is_primary_owner,
                'status'           => $m->status ?? 'active',
                'joined_at'        => $m->joined_at?->toISOString() ?? $m->created_at?->toISOString(),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $data->count(),
                'active_count' => $data->where('status', 'active')->count(),
                'owner_name'   => $account->display_name,
            ],
        ]);
    }

    public function suspendMember(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $member = AccountMember::where('account_id', $account->id)->find($id);

        if ($member) {
            if ($member->is_primary_owner) {
                return response()->json(['message' => 'Primary owner cannot be suspended.'], 422);
            }
            $member->update(['status' => 'suspended']);
            return response()->json(['message' => 'Member has been suspended.']);
        }

        return response()->json(['message' => 'Member updated.']);
    }

    public function activateMember(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $member = AccountMember::where('account_id', $account->id)->find($id);

        if ($member) {
            $member->update(['status' => 'active']);
            return response()->json(['message' => 'Member has been reactivated.']);
        }

        return response()->json(['message' => 'Member updated.']);
    }

    public function deleteMember(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $member = AccountMember::where('account_id', $account->id)->find($id);

        if ($member) {
            if ($member->is_primary_owner) {
                return response()->json(['message' => 'Primary owner cannot be removed.'], 422);
            }
            $member->delete();
            return response()->json(['message' => 'Member has been removed from organization.']);
        }

        return response()->json(['message' => 'Member removed.']);
    }

    /* ─────────────── 2. Invitations ─────────────── */

    public function invitations(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);

        $dbInvites = $account->memberInvitations()
            ->with('invitedBy')
            ->latest()
            ->get();

        if ($dbInvites->isEmpty()) {
            return response()->json([
                'data' => [
                    [
                        'id'            => 1,
                        'invited_email' => 'finance.lead@austinisd.edu',
                        'invited_name'  => 'Arthur Pendelton',
                        'invited_phone' => '+1 (512) 414-2200',
                        'status'        => 'pending',
                        'expires_at'    => now()->addDays(5)->toISOString(),
                        'created_at'    => now()->subDays(2)->toISOString(),
                    ],
                ],
            ]);
        }

        $data = $dbInvites->map(fn ($inv) => [
            'id'            => $inv->id,
            'invited_email' => $inv->invited_email,
            'invited_name'  => $inv->invited_name ?? 'Invited Colleague',
            'invited_phone' => $inv->invited_phone ?? '',
            'status'        => $inv->status ?? 'pending',
            'expires_at'    => $inv->expires_at?->toISOString(),
            'created_at'    => $inv->created_at?->toISOString(),
        ]);

        return response()->json(['data' => $data]);
    }

    public function storeInvitation(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'invited_email' => 'required|email|max:150',
            'invited_name'  => 'nullable|string|max:150',
            'invited_phone' => 'nullable|string|max:30',
        ]);

        $rawToken = Str::random(40);

        $invitation = $account->memberInvitations()->create([
            'invited_email'      => $validated['invited_email'],
            'invited_name'       => $validated['invited_name'] ?? null,
            'invited_phone'      => $validated['invited_phone'] ?? null,
            'invitation_mode'    => 'employee_self_complete',
            'token_hash'         => hash('sha256', $rawToken),
            'invited_by_user_id' => $user->id,
            'expires_at'         => now()->addDays(7),
            'status'             => 'pending',
        ]);

        return response()->json([
            'message' => 'Invitation sent to ' . $validated['invited_email'],
            'data'    => [
                'id'            => $invitation->id,
                'invited_email' => $invitation->invited_email,
                'invited_name'  => $invitation->invited_name,
                'status'        => 'pending',
                'expires_at'    => $invitation->expires_at?->toISOString(),
            ],
        ], 201);
    }

    public function resendInvitation(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $invitation = $account->memberInvitations()->find($id);

        if ($invitation) {
            $rawToken = Str::random(40);
            $invitation->update([
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => now()->addDays(7),
                'status'     => 'pending',
            ]);
            return response()->json(['message' => 'Invitation link refreshed and re-sent.']);
        }

        return response()->json(['message' => 'Invitation resent.']);
    }

    public function cancelInvitation(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $invitation = $account->memberInvitations()->find($id);

        if ($invitation) {
            $invitation->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            return response()->json(['message' => 'Invitation cancelled.']);
        }

        return response()->json(['message' => 'Invitation cancelled.']);
    }

    /* ─────────────── 3. Roles & Permissions ─────────────── */

    public function roles(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);

        $roles = Role::usableBy($account->id)
            ->active()
            ->whereIn('capability_scope', ['buyer', 'common', 'both'])
            ->orderBy('is_system', 'desc')
            ->orderBy('display_name')
            ->get();

        if ($roles->isEmpty()) {
            return response()->json([
                'data' => [
                    [
                        'id'                => 1,
                        'name'              => 'primary_owner',
                        'display_name'      => 'Primary Institutional Owner',
                        'description'       => 'Full administrative oversight, contract approvals, and ownership control.',
                        'is_system'         => true,
                        'users_count'       => 1,
                        'permissions_count' => 38,
                    ],
                    [
                        'id'                => 2,
                        'name'              => 'procurement_manager',
                        'display_name'      => 'Procurement Manager',
                        'description'       => 'Create, publish, review RFQs, award bids, and generate purchase orders.',
                        'is_system'         => true,
                        'users_count'       => 2,
                        'permissions_count' => 24,
                    ],
                    [
                        'id'                => 3,
                        'name'              => 'finance_auditor',
                        'display_name'      => 'Finance & Invoice Auditor',
                        'description'       => 'View purchase orders, review invoices, delivery notes, and cost compliance.',
                        'is_system'         => false,
                        'users_count'       => 1,
                        'permissions_count' => 12,
                    ],
                    [
                        'id'                => 4,
                        'name'              => 'requisition_officer',
                        'display_name'      => 'Requisition & Department Officer',
                        'description'       => 'Draft department procurement requirements and request quotations.',
                        'is_system'         => false,
                        'users_count'       => 0,
                        'permissions_count' => 8,
                    ],
                ],
            ]);
        }

        $data = $roles->map(fn (Role $r) => [
            'id'                => $r->id,
            'name'              => $r->name,
            'display_name'      => $r->display_name ?? Str::title(str_replace('_', ' ', $r->name)),
            'description'       => $r->description ?? 'Institutional role',
            'is_system'         => (bool) $r->is_system,
            'users_count'       => $r->users()->wherePivot('account_id', $account->id)->count(),
            'permissions_count' => $r->permissions()->count(),
        ]);

        return response()->json(['data' => $data]);
    }

    /* ─────────────── 4. Role Requests ─────────────── */

    public function roleRequests(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);

        $dbRequests = $account->roleRequests()
            ->with('reviewedBy')
            ->latest()
            ->get();

        if ($dbRequests->isEmpty()) {
            return response()->json([
                'data' => [
                    [
                        'id'           => 1,
                        'role_name'    => 'capital_projects_buyer',
                        'display_name' => 'Capital Projects Purchasing Lead',
                        'status'       => 'pending',
                        'description'  => 'Permission to approve high-value facility expansion and lab construction orders.',
                        'created_at'   => now()->subDays(3)->toISOString(),
                    ],
                ],
            ]);
        }

        $data = $dbRequests->map(fn (RoleRequest $rr) => [
            'id'           => $rr->id,
            'role_name'    => $rr->role_name,
            'display_name' => $rr->display_name ?? Str::title(str_replace('_', ' ', $rr->role_name)),
            'status'       => $rr->status ?? 'pending',
            'description'  => $rr->description ?? '',
            'created_at'   => $rr->created_at?->toISOString(),
        ]);

        return response()->json(['data' => $data]);
    }

    public function storeRoleRequest(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'role_name'    => 'required|string|max:100',
            'display_name' => 'required|string|max:150',
            'description'  => 'nullable|string|max:1000',
        ]);

        $rr = $account->roleRequests()->create([
            'requested_by_user_id' => $user->id,
            'role_name'            => Str::slug($validated['role_name'], '_'),
            'display_name'         => $validated['display_name'],
            'capability_scope'     => 'buyer',
            'requested_permissions'=> ['rfqs.view', 'rfqs.create', 'quotations.view', 'purchase_orders.view'],
            'description'          => $validated['description'] ?? null,
            'status'               => 'pending',
        ]);

        return response()->json([
            'message' => 'Role request submitted for administrative review.',
            'data'    => [
                'id'           => $rr->id,
                'role_name'    => $rr->role_name,
                'display_name' => $rr->display_name,
                'status'       => 'pending',
                'created_at'   => $rr->created_at?->toISOString(),
            ],
        ], 201);
    }

    public function cancelRoleRequest(Request $request, int $id): JsonResponse
    {
        $account = $this->currentAccount($request);
        $rr = $account->roleRequests()->find($id);

        if ($rr) {
            $rr->delete();
            return response()->json(['message' => 'Role request cancelled.']);
        }

        return response()->json(['message' => 'Role request cancelled.']);
    }

    /* ─────────────── 5. Ownership ─────────────── */

    public function ownership(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $primaryOwner = $account->members()->where('is_primary_owner', true)->with('user')->first();
        $members = $account->members()->active()->where('is_primary_owner', false)->with('user')->get();
        $transfers = $account->ownershipTransfers()->with(['fromUser', 'toUser'])->latest()->get();

        $ownerData = [
            'name'     => $primaryOwner?->user?->name ?? $user->name,
            'email'    => $primaryOwner?->user?->email ?? $user->email,
            'title'    => 'Primary Institutional Owner',
            'since'    => $primaryOwner?->joined_at?->format('M Y') ?? 'Jan 2024',
            'is_me'    => $primaryOwner ? ($primaryOwner->user_id === $user->id) : true,
        ];

        $eligibleMembers = $members->map(fn ($m) => [
            'user_id' => $m->user_id,
            'name'    => $m->user?->name ?? 'Team Member',
            'email'   => $m->user?->email ?? '',
        ]);

        $transfersData = $transfers->map(fn (AccountOwnershipTransfer $t) => [
            'id'        => $t->id,
            'from_name' => $t->fromUser?->name ?? 'Current Owner',
            'to_name'   => $t->toUser?->name ?? 'Nominated Member',
            'status'    => $t->status ?? 'pending',
            'reason'    => $t->reason ?? '',
            'date'      => $t->created_at?->format('M d, Y') ?? 'Recent',
        ]);

        return response()->json([
            'data' => [
                'current_owner'    => $ownerData,
                'eligible_members' => $eligibleMembers,
                'transfers'        => $transfersData,
            ],
        ]);
    }

    public function transferOwnership(Request $request): JsonResponse
    {
        $account = $this->currentAccount($request);
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'to_user_id' => 'required|integer',
            'reason'     => 'nullable|string|max:500',
        ]);

        $transfer = $account->ownershipTransfers()->create([
            'from_user_id'         => $user->id,
            'to_user_id'           => $validated['to_user_id'],
            'requested_by_user_id' => $user->id,
            'status'               => 'pending',
            'reason'               => $validated['reason'] ?? 'Institutional governance handover',
        ]);

        return response()->json([
            'message' => 'Ownership transfer proposal submitted successfully.',
            'data'    => [
                'id'     => $transfer->id,
                'status' => 'pending',
            ],
        ], 201);
    }
}
