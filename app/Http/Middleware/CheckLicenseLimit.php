<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckLicenseLimit
{
    public function handle(Request $request, Closure $next, string $limitType, ?string $moduleName = null): Response
    {
        $user = $request->user();

        if (! $user || ! $user->organization_id) {
            return $next($request);
        }

        $license = $user->organization->license;

        if (! $license || $license->status !== 'active') {
            abort(402, 'Active license required.');
        }

        switch ($limitType) {
            case 'module':
                if ($moduleName && ! $license->hasModule($moduleName)) {
                    abort(403, "Module {$moduleName} is not enabled on your current plan.");
                }
                break;
            case 'branches':
                $currentBranches = $user->organization->branches()->count(); // Assuming branches relationship exists or will exist
                if (! $license->canAddBranch($currentBranches)) {
                    abort(402, 'Branch limit reached on your current plan. Please upgrade.');
                }
                break;
            case 'users':
                $currentUsers = User::where('organization_id', $user->organization_id)->count();
                if (! $license->canAddUser($currentUsers)) {
                    abort(402, 'User limit reached on your current plan. Please upgrade.');
                }
                break;
        }

        return $next($request);
    }
}
