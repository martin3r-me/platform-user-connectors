<?php

namespace Platform\UserConnectors;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use Platform\UserConnectors\Console\Commands\RenewWebhookSubscriptions;
use Platform\UserConnectors\Services\Microsoft365\Microsoft365SubscriptionService;
use Platform\UserConnectors\Services\RingCentral\RingCentralSubscriptionService;
use Platform\UserConnectors\Services\Vodafone\VodafoneSubscriptionService;
use Platform\UserConnectors\Services\WebhookSubscriptionManager;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class UserConnectorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/user-connectors.php', 'user-connectors');

        $this->app->singleton(WebhookSubscriptionManager::class, function ($app) {
            $manager = new WebhookSubscriptionManager();
            $manager->registerConnector($app->make(Microsoft365SubscriptionService::class));
            $manager->registerConnector($app->make(RingCentralSubscriptionService::class));
            $manager->registerConnector($app->make(VodafoneSubscriptionService::class));

            return $manager;
        });
    }

    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/user-connectors.php', 'user-connectors');

        // Register module (if module system available)
        if (
            config()->has('user-connectors.routing') &&
            config()->has('user-connectors.navigation') &&
            Schema::hasTable('modules')
        ) {
            PlatformCore::registerModule([
                'key' => 'user-connectors',
                'title' => 'User Connectors',
                'group' => 'admin',
                'routing' => config('user-connectors.routing'),
                'guard' => config('user-connectors.guard'),
                'navigation' => config('user-connectors.navigation'),
            ]);
        }

        // OAuth2 routes (always available, require auth)
        Route::prefix('user-connectors')
            ->middleware(['web', 'auth'])
            ->group(function () {
                Route::get('/oauth2/{connectorKey}/start', [\Platform\UserConnectors\Http\Controllers\OAuth2Controller::class, 'start'])
                    ->name('user-connectors.oauth2.start');
                Route::get('/oauth2/{connectorKey}/callback', [\Platform\UserConnectors\Http\Controllers\OAuth2Controller::class, 'callback'])
                    ->name('user-connectors.oauth2.callback');
            });

        // Webhook routes (unauthenticated — providers send data directly)
        Route::prefix('user-connectors/webhooks')
            ->middleware(['web'])
            ->group(function () {
                Route::post('/sipgate', [\Platform\UserConnectors\Http\Controllers\WebhookController::class, 'sipgate'])
                    ->name('user-connectors.webhooks.sipgate')
                    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
                Route::post('/microsoft365', [\Platform\UserConnectors\Http\Controllers\WebhookController::class, 'microsoft365'])
                    ->name('user-connectors.webhooks.microsoft365')
                    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
                Route::post('/ringcentral', [\Platform\UserConnectors\Http\Controllers\WebhookController::class, 'ringcentral'])
                    ->name('user-connectors.webhooks.ringcentral')
                    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
                Route::post('/vodafone', [\Platform\UserConnectors\Http\Controllers\WebhookController::class, 'ringcentral'])
                    ->name('user-connectors.webhooks.vodafone')
                    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
                Route::post('/microsoft365/call-records', [\Platform\UserConnectors\Http\Controllers\WebhookController::class, 'microsoft365CallRecords'])
                    ->name('user-connectors.webhooks.microsoft365.call-records')
                    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
            });

        // Module routes via ModuleRouter (when module is active)
        if (PlatformCore::getModule('user-connectors')) {
            $routesPath = __DIR__ . '/../routes/web.php';
            ModuleRouter::group('user-connectors', function () use ($routesPath) {
                require $routesPath;
            });
        }

        // Migrations, Views, Livewire
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'user-connectors');
        $this->registerLivewireComponents();

        // Commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                RenewWebhookSubscriptions::class,
                Console\Commands\BackfillSessions::class,
            ]);
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('user-connectors:renew-webhook-subscriptions')
                ->hourly()
                ->withoutOverlapping();
        });

        // LLM Tools
        $this->registerTools();

        // PersonActivityProvider registrieren (loose Kopplung mit Organization-Modul)
        try {
            resolve(\Platform\Organization\Services\PersonActivityRegistry::class)
                ->register(new \Platform\UserConnectors\Organization\UserConnectorsPersonActivityProvider());
        } catch (\Throwable $e) {
            // Organization-Modul nicht geladen
        }

        // Config publish
        $this->publishes([
            __DIR__ . '/../config/user-connectors.php' => config_path('user-connectors.php'),
        ], 'user-connectors-config');
    }

    protected function registerTools(): void
    {
        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);

            // Microsoft 365 Tools
            $registry->register(new \Platform\UserConnectors\Tools\Microsoft365\TestConnectionTool());
            $registry->register(new \Platform\UserConnectors\Tools\Microsoft365\ListMailTool());
            $registry->register(new \Platform\UserConnectors\Tools\Microsoft365\SendMailTool());
            $registry->register(new \Platform\UserConnectors\Tools\Microsoft365\ListEventsTool());
            $registry->register(new \Platform\UserConnectors\Tools\Microsoft365\CreateEventTool());
        } catch (\Throwable $e) {
            \Log::warning('UserConnectors: Microsoft 365 Tool-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);
        }

        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);

            // RingCentral Tools
            $registry->register(new \Platform\UserConnectors\Tools\RingCentral\TestConnectionTool());
            $registry->register(new \Platform\UserConnectors\Tools\RingCentral\GetCallLogTool());
            $registry->register(new \Platform\UserConnectors\Tools\RingCentral\SendSMSTool());
            $registry->register(new \Platform\UserConnectors\Tools\RingCentral\InitiateCallTool());
        } catch (\Throwable $e) {
            \Log::warning('UserConnectors: RingCentral Tool-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);
        }

        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);

            // Sipgate Tools
            $registry->register(new \Platform\UserConnectors\Tools\Sipgate\TestConnectionTool());
            $registry->register(new \Platform\UserConnectors\Tools\Sipgate\GetCallLogTool());
            $registry->register(new \Platform\UserConnectors\Tools\Sipgate\SendSMSTool());
            $registry->register(new \Platform\UserConnectors\Tools\Sipgate\InitiateCallTool());
        } catch (\Throwable $e) {
            \Log::warning('UserConnectors: Sipgate Tool-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);
        }

        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);

            // Generic Phone Numbers & Devices Tools
            $registry->register(new \Platform\UserConnectors\Tools\ListPhoneNumbersTool());
            $registry->register(new \Platform\UserConnectors\Tools\ListDevicesTool());
            $registry->register(new \Platform\UserConnectors\Tools\SyncProfileTool());
        } catch (\Throwable $e) {
            \Log::warning('UserConnectors: Phone/Devices Tool-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);
        }

        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);

            // Cross-Connector Query Tools
            $registry->register(new \Platform\UserConnectors\Tools\ListConnectionsTool());
            $registry->register(new \Platform\UserConnectors\Tools\ListEventsTool());
            $registry->register(new \Platform\UserConnectors\Tools\ListCallSessionsTool());
            $registry->register(new \Platform\UserConnectors\Tools\ListMailSessionsTool());
            $registry->register(new \Platform\UserConnectors\Tools\ListMeetingSessionsTool());
            $registry->register(new \Platform\UserConnectors\Tools\ListMessageSessionsTool());
            $registry->register(new \Platform\UserConnectors\Tools\DiagnoseConnectionTool());
            $registry->register(new \Platform\UserConnectors\Tools\ReprocessEventTool());
        } catch (\Throwable $e) {
            \Log::warning('UserConnectors: Query Tool-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }

    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__ . '/Livewire';
        $baseNamespace = 'Platform\\UserConnectors\\Livewire';
        $prefix = 'user-connectors';

        if (!is_dir($basePath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $class = $baseNamespace . '\\' . $classPath;

            if (!class_exists($class)) {
                continue;
            }

            $aliasPath = str_replace(['\\', '/'], '.', Str::kebab(str_replace('.php', '', $relativePath)));
            $alias = $prefix . '.' . $aliasPath;

            Livewire::component($alias, $class);
        }
    }
}
