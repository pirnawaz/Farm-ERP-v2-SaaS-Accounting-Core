<?php

namespace App\Http\Controllers;

use App\Helpers\AuthCookie;
use App\Helpers\AuthToken;
use App\Http\Requests\AcceptInviteRequest;
use App\Models\IdentityAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\IdentityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AcceptInviteController extends Controller
{
    /**
     * Accept invitation: token + name + new_password. Creates user, sets cookie, returns auth shape.
     * POST /api/auth/accept-invite (public, no tenant header)
     * Token consumption is transactional with lockForUpdate to prevent concurrent double-accept.
     */
    public function __invoke(AcceptInviteRequest $request): JsonResponse
    {
        $v = $request->validated();

        $result = DB::transaction(function () use ($v) {
            $invitation = UserInvitation::consumeToken($v['token']);
            if (!$invitation) {
                return null;
            }
            if (User::where('tenant_id', $invitation->tenant_id)->where('email', $invitation->email)->exists()) {
                throw new \Illuminate\Validation\ValidationException(
                    validator([], []),
                    response()->json(['error' => 'User already exists for this email'], 422)
                );
            }
            $user = User::create([
                'tenant_id' => $invitation->tenant_id,
                'name' => $v['name'],
                'email' => $invitation->email,
                'password' => Hash::make($v['new_password']),
                'role' => $invitation->role,
                'is_enabled' => true,
            ]);
            return [$invitation, $user];
        });

        if ($result === null) {
            return response()->json(['error' => 'Invalid or expired invitation token'], 400);
        }

        [$invitation, $user] = $result;
        IdentityAuditLogger::log(IdentityAuditLog::ACTION_INVITATION_ACCEPTED, $invitation->tenant_id, $invitation->invited_by_user_id, ['user_id' => $user->id, 'email' => $user->email], $request);

        $token = AuthToken::create($user, $user->tenant_id);
        $cookie = AuthCookie::make($token);
        $tenant = Tenant::find($user->tenant_id);

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug ?? null,
            ] : null,
        ])->cookie($cookie);
    }
}
