<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Capabilities\Helpers;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\HelperRegistry;
use Andre\AiPageBuilder\Models\PbUser;
use Illuminate\Support\Facades\Auth;

/**
 * Auth helpers — read the current end-user (the `pb` guard) from inside a Function.
 * Useful for stamping ownership, gating logic, or personalising flow output. The
 * user array is the model's safe `toArray()` (password / 2FA columns are hidden).
 */
class AuthHelpers implements HelperProvider
{
    public function register(HelperRegistry $registry): void
    {
        $registry->register(
            new CapabilityDefinition(
                key: 'auth_user',
                label: 'auth.user',
                category: CapabilityCategory::Auth,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'The signed-in end-user as an array (sensitive columns hidden), or null when a guest.',
                usage: "db_create('orders', {customer_id: auth_id(), total: vars.total})",
                inputs: [],
            ),
            static fn (): ?array => self::userArray(),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'auth_id',
                label: 'auth.id',
                category: CapabilityCategory::Auth,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'The signed-in end-user id, or null when a guest.',
                usage: 'auth_id()',
                inputs: [],
            ),
            static fn (): int|string|null => self::user()?->getKey(),
        );

        $registry->register(
            new CapabilityDefinition(
                key: 'auth_check',
                label: 'auth.check',
                category: CapabilityCategory::Auth,
                kind: CapabilityDefinition::KIND_HELPER,
                description: 'True when an end-user is signed in.',
                usage: 'auth_check()',
                inputs: [],
            ),
            static fn (): bool => self::user() !== null,
        );
    }

    /**
     * The signed-in user's own record for server-side code: the model's safe
     * toArray() plus their own email and role id (hidden from serialization so
     * they never leak to OTHER callers, but a flow emailing the current user
     * needs them). Credentials and two-factor state stay out.
     *
     * @return array<string,mixed>|null
     */
    private static function userArray(): ?array
    {
        $user = self::user();

        if ($user === null) {
            return null;
        }

        return array_merge($user->toArray(), [
            'email' => $user->getAttribute('email'),
            'role_id' => $user->getAttribute('role_id'),
        ]);
    }

    private static function user(): ?PbUser
    {
        $guard = (string) config('ai-page-builder.auth.guard', 'pb');
        $user = Auth::guard($guard)->user();

        return $user instanceof PbUser ? $user : null;
    }
}
