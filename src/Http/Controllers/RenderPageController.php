<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\AccessControl;
use Andre\AiPageBuilder\Services\PageRenderer;
use Andre\AiPageBuilder\Services\Settings;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RenderPageController
{
    /**
     * Render a published page by slug at `/{prefix}/{slug}`.
     */
    public function __invoke(string $slug, PageRenderer $renderer, Settings $settings): SymfonyResponse
    {
        if ($maintenance = $this->maintenanceResponse($renderer, $settings)) {
            return $maintenance;
        }

        return $this->renderSlug($slug, $renderer, $settings);
    }

    /**
     * Render the configured home page at the render-prefix root (and, when
     * `routes.home_at_root` is on, at the site root `/`). The home page is the
     * one whose slug is stored in the `home_page` setting; renders the 404 page
     * if none is set or it is not published.
     */
    public function home(PageRenderer $renderer, Settings $settings): SymfonyResponse
    {
        if ($maintenance = $this->maintenanceResponse($renderer, $settings)) {
            return $maintenance;
        }

        // Defaults to the seeded `home` starter page so a fresh install has a
        // working home out of the box; a host can point it elsewhere in Settings.
        $slug = $settings->get('home_page', 'home');

        if (! is_string($slug) || $slug === '') {
            return $this->notFound($renderer, $settings);
        }

        return $this->renderSlug($slug, $renderer, $settings);
    }

    private function renderSlug(string $slug, PageRenderer $renderer, Settings $settings): SymfonyResponse
    {
        /** @var class-string<Page> $model */
        $model = config('ai-page-builder.models.page', Page::class);

        $page = $model::query()->published()->where('slug', $slug)->first();

        if ($page === null) {
            // Distinguish "taken down" from "never existed": a slug that has a
            // page row but isn't published reads as under maintenance (503) —
            // unpublishing a live page shows the maintenance page; a slug with
            // no row at all is a genuine 404.
            $exists = $model::query()->where('slug', $slug)->exists();

            return $exists
                ? $this->maintenancePage($renderer, $settings)
                : $this->notFound($renderer, $settings);
        }

        // Both gates run BEFORE the render cache is touched: the cache is keyed
        // by slug only, so it must never be what decides who sees a page.
        if ($denied = self::denyAccess($page, fn (): SymfonyResponse => $this->notFound($renderer, $settings))) {
            return $denied;
        }

        return self::withSecurityHeaders(new Response($renderer->renderCached($page)));
    }

    /**
     * The page-level gates shared by the render and preview routes, or null
     * when the visitor may see the page:
     *   - `requires_auth`: guests are sent to the login page (with an intended
     *     URL so they come back after signing in);
     *   - role permissions (`resource_type=page`, action `view`): once any role
     *     has a permission row for the page it is restricted, and only a user
     *     whose role is granted `view` (or an admin) may see it. Guests go to
     *     login; a signed-in user without the grant gets $forbidden (the 404
     *     page), so the response never confirms the page exists.
     *
     * @param  callable():SymfonyResponse  $forbidden
     */
    public static function denyAccess(Page $page, callable $forbidden): ?SymfonyResponse
    {
        if (! config('ai-page-builder.auth.enabled', true)) {
            return null;
        }

        $access = app(AccessControl::class);
        $user = $access->currentUser();

        if ($page->requires_auth && $user === null) {
            return self::loginRedirect();
        }

        if ($access->can($user, 'view', 'page', (string) $page->slug)) {
            return null;
        }

        return $user === null ? self::loginRedirect() : $forbidden();
    }

    /**
     * Response headers for every rendered page. The Content-Security-Policy is
     * the configurable `render.content_security_policy` (null / '' sends none).
     */
    public static function withSecurityHeaders(SymfonyResponse $response): SymfonyResponse
    {
        $csp = config('ai-page-builder.render.content_security_policy');
        if (is_string($csp) && trim($csp) !== '') {
            $response->headers->set('Content-Security-Policy', trim($csp));
        }

        return $response;
    }

    private static function loginRedirect(): SymfonyResponse
    {
        return redirect()->guest('/'.trim((string) config('ai-page-builder.auth.login_path', 'login'), '/'));
    }

    /**
     * A 503 maintenance response when maintenance mode is on, else null. Admins
     * (a signed-in end-user with is_admin) bypass so they can still browse the
     * site while it's closed to visitors.
     */
    private function maintenanceResponse(PageRenderer $renderer, Settings $settings): ?SymfonyResponse
    {
        if (! filter_var($settings->get('maintenance_mode', false), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        if ($this->isAdminBypass()) {
            return null;
        }

        return $this->maintenancePage($renderer, $settings);
    }

    /** Render the maintenance page at HTTP 503 (configured page, else built-in view). */
    private function maintenancePage(PageRenderer $renderer, Settings $settings): SymfonyResponse
    {
        return $this->renderSpecial($renderer, $settings, 'maintenance_page', 'maintenance', 'maintenance', SymfonyResponse::HTTP_SERVICE_UNAVAILABLE);
    }

    private function notFound(PageRenderer $renderer, Settings $settings): SymfonyResponse
    {
        return $this->renderSpecial($renderer, $settings, 'not_found_page', 'not-found', 'not-found', SymfonyResponse::HTTP_NOT_FOUND);
    }

    /**
     * Render the page whose slug is in $settingKey (default $defaultSlug) at
     * $status. Falls back to the bundled `render.system.{$view}` blade when that
     * page is missing — so a fresh / un-seeded install still shows something sane.
     */
    private function renderSpecial(PageRenderer $renderer, Settings $settings, string $settingKey, string $defaultSlug, string $view, int $status): SymfonyResponse
    {
        $slug = $settings->get($settingKey);
        $slug = is_string($slug) && $slug !== '' ? $slug : $defaultSlug;

        /** @var class-string<Page> $model */
        $model = config('ai-page-builder.models.page', Page::class);
        $page = $model::query()->published()->where('slug', $slug)->first();

        // A restricted or login-only page configured as the 404 / maintenance
        // page is only shown to visitors allowed to see it; everyone else gets
        // the bundled fallback view.
        if ($page !== null && self::denyAccess($page, static fn (): SymfonyResponse => new Response('', $status)) === null) {
            return self::withSecurityHeaders(new Response($renderer->renderCached($page), $status));
        }

        return self::withSecurityHeaders(new Response(view('ai-page-builder::render.system.'.$view)->render(), $status));
    }

    private function isAdminBypass(): bool
    {
        if (! config('ai-page-builder.auth.enabled', true)) {
            return false;
        }

        $user = Auth::guard((string) config('ai-page-builder.auth.guard', 'pb'))->user();

        // Admin status lives on the ROLE (role.is_admin), exposed via isAdmin() —
        // there is no `is_admin` column on the user. (AuthController::me() already
        // uses isAdmin(); this used the non-existent attribute → the bypass never
        // fired and admins were locked out with everyone else during maintenance.)
        return $user !== null && method_exists($user, 'isAdmin') && $user->isAdmin();
    }
}
