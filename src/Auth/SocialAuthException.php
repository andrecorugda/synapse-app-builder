<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Auth;

use RuntimeException;

/**
 * Thrown when an SSO sign-in is rejected by policy — an email outside the
 * allowed domains, a user not in an allowed GitHub org, or a missing email from
 * the provider. The controller turns it into a friendly login-page error.
 */
class SocialAuthException extends RuntimeException {}
