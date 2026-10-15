<?php

namespace App\Providers\Filament;

use App\Http\Controllers\Review\PostingPdfController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ReviewPanelProvider extends PanelProvider
{
    /**
     * The panel's id, which is also the first part of every route name it
     * registers. Anything generating a URL into the panel reads it from here.
     */
    public const ID = 'review';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id(self::ID)
            ->path('review')
            ->brandName(fn (): string => __('panel.brand'))
            ->login()
            ->colors([
                'primary' => Color::Slate,
            ])
            // Two items the owner knows by heart, against a list that is
            // worth the width. So the sidebar collapses to a rail of icons,
            // and because Filament keeps that open state in a persisted
            // client-side store, it is collapsed once and stays collapsed.
            // Collapsible is the whole of what a panel can ask for: there is
            // no starting state to set.
            ->sidebarCollapsibleOnDesktop()
            // A posting's CV, served inline for the review screen to embed.
            // `authenticatedRoutes` puts it inside the panel's own middleware
            // and behind the panel's login, which is what makes the session
            // the only way to it: a route registered in `routes/web.php` would
            // need the panel's authentication bolted on by hand, and one
            // behind a signature would be a second credential for the same
            // document.
            ->authenticatedRoutes(function (): void {
                Route::get('postings/{posting}/cv.pdf', PostingPdfController::class)
                    ->name(PostingPdfController::ROUTE);
            })
            // No dashboard is registered. Filament serves the panel's root by
            // sending the browser to the first item in the navigation, so the
            // postings list is what signing in arrives at; a dashboard would
            // only stand in front of it holding nothing.
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
