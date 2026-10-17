<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\RolePolicy;
use App\Services\Auth\Google\GoogleAuthIdTokenVerifier;
use App\Services\Auth\Google\GoogleIdTokenVerifier;
use App\Services\Push\FcmDriver;
use App\Services\Push\FcmDriverInterface;
use App\Services\Push\LogFcmDriver;
use App\Services\Sms\LogSmsProvider;
use App\Services\Sms\SmsInterface;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // SMS: swap LogSmsProvider for a real provider (Twilio, Vonage, etc.) in production.
        $this->app->singleton(SmsInterface::class, LogSmsProvider::class);

        $this->app->singleton(GoogleIdTokenVerifier::class, fn (): GoogleIdTokenVerifier => new GoogleAuthIdTokenVerifier(
            (array) config('services.google.client_ids'),
        ));

        // Use the real FCM driver when Firebase credentials are configured; fall back to log driver.
        $this->app->singleton(FcmDriverInterface::class, function (): FcmDriverInterface {
            $credentials = config('firebase.projects.app.credentials');

            if (filled($credentials)) {
                return new FcmDriver(Firebase::project('app')->messaging());
            }

            return new LogFcmDriver;
        });
    }

    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        Passport::enablePasswordGrant();
        Passport::tokensExpireIn(now()->addHours(8));
        Passport::refreshTokensExpireIn(now()->addDays(30));

        // spatie's Role model lives in the vendor namespace, so its policy
        // must be registered explicitly (User's policy is auto-discovered).
        Gate::policy(Role::class, RolePolicy::class);

        // super-admin bypasses every authorization check (API + back-office).
        Gate::before(fn (?User $user, string $ability): ?bool => ($user && $user->hasRole('super-admin')) ? true : null);

        // Inject high-quality touch icons, favicons and manifest into Filament head
        if (class_exists(FilamentView::class)) {
            FilamentView::registerRenderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render('
                    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset(\'apple-touch-icon.png\') }}">
                    <link rel="apple-touch-icon-precomposed" href="{{ asset(\'apple-touch-icon-precomposed.png\') }}">
                    <link rel="icon" type="image/svg+xml" href="{{ asset(\'favicon.svg\') }}">
                    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset(\'favicon-32x32.png\') }}">
                    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset(\'favicon-16x16.png\') }}">
                    <link rel="manifest" href="{{ asset(\'site.webmanifest\') }}">
                    <meta name="theme-color" content="#2563eb">
                ')
            );
        }

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(SecurityScheme::http('bearer'));
            });
    }
}
