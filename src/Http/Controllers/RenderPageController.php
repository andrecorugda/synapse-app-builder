<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Models\Page;
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

        // Page-level gate: a page flagged requires_auth is served only to a
        // logged-in end-user; guests are sent to the login page (with intended
        // so they return here after signing in).
        if ($page->requires_auth && config('ai-page-builder.auth.enabled', true)) {
            $guard = (string) config('ai-page-builder.auth.guard', 'pb');
            if (! Auth::guard($guard)->check()) {
                return redirect()->guest('/'.trim((string) config('ai-page-builder.auth.login_path', 'login'), '/'));
            }
        }

        return new Response($renderer->renderCached($page));
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

        if ($page !== null) {
            return new Response($renderer->renderCached($page), $status);
        }

        return new Response(view('ai-page-builder::render.system.'.$view)->render(), $status);
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
