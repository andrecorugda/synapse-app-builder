<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Models\PbPermission;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * The permission engine for the BUILT app's end-users. Permissions are
 * OPT-IN per resource: a collection/page with no permission rows is open to
 * everyone (so a public site / an app without auth keeps working untouched).
 * Once any role defines a permission for a resource, it becomes restricted and
 * only granted roles/actions pass. `is_admin` roles bypass every check.
 *
 * Row-level rules live on the same permission rows: a rule like
 * {"owner_id": "$CURRENT_USER"} narrows a collection's reads/writes to the
 * acting user's own rows.
 */
class AccessControl
{
    /** Resolve the currently authenticated end-user (pb guard), if any. */
    public function currentUser(): ?Authenticatable
    {
        if (! (bool) config('ai-page-builder.auth.enabled', true)) {
            return null;
        }

        return Auth::guard((string) config('ai-page-builder.auth.guard', 'pb'))->user();
    }

    /**
     * May $user perform $action on a resource? Opt-in: unrestricted resources
     * are always allowed; restricted ones require a matching grant.
     */
    public function can(?Authenticatable $user, string $action, string $type, string $key): bool
    {
        if (! (bool) config('ai-page-builder.auth.enabled', true)) {
            return true;
        }

        if (! $this->isRestricted($type, $key)) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        $roleId = $this->roleId($user);
        if ($roleId === null) {
            return false;
        }

        return PbPermission::query()
            ->where('role_id', $roleId)
            ->where('resource_type', $type)
            ->whereIn('resource_key', [$key, '*'])
            ->whereIn('action', [$action, '*'])
            ->exists();
    }

    /** True when ANY role defines a permission for this resource (key or '*'). */
    public function isRestricted(string $type, string $key): bool
    {
        return PbPermission::query()
            ->where('resource_type', $type)
            ->whereIn('resource_key', [$key, '*'])
            ->exists();
    }

    /**
     * The resolved row-level rule (field => value) for a collection action, or
     * an empty array when unrestricted. `$CURRENT_USER` resolves to the acting
     * user's id. Admins / unrestricted collections get no rule.
     *
     * @return array<string,mixed>
     */
    public function rowRule(?Authenticatable $user, string $collectionKey, string $action): array
    {
        if (! (bool) config('ai-page-builder.auth.enabled', true) || $user === null || $this->isAdmin($user)) {
            return [];
        }

        $roleId = $this->roleId($user);
        if ($roleId === null) {
            return [];
        }

        $perm = PbPermission::query()
            ->where('role_id', $roleId)
            ->where('resource_type', 'collection')
            ->whereIn('resource_key', [$collectionKey, '*'])
            ->whereIn('action', [$action, '*'])
            ->whereNotNull('rule')
            ->first();

        $rule = $perm?->rule;
        if (! is_array($rule)) {
            return [];
        }

        $uid = $user->getAuthIdentifier();

        return array_map(
            static fn ($v) => $v === '$CURRENT_USER' ? $uid : $v,
            $rule,
        );
    }

    /**
     * True when a record satisfies every field in a resolved row rule.
     *
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $rule
     */
    public function recordMatchesRule(array $record, array $rule): bool
    {
        foreach ($rule as $field => $value) {
            if (($record[$field] ?? null) != $value) { // loose: ids may be int vs string
                return false;
            }
        }

        return true;
    }

    private function isAdmin(Authenticatable $user): bool
    {
        return method_exists($user, 'isAdmin') && $user->isAdmin();
    }

    private function roleId(Authenticatable $user): int|string|null
    {
        return $user->getAttribute('role_id');
    }
}
