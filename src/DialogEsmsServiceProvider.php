<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms;

use CodeRayTech\DialogEsms\Contracts\SmsGateway;
use CodeRayTech\DialogEsms\Http\Controllers\DeliveryReceiptController;
use CodeRayTech\DialogEsms\Notifications\DialogEsmsChannel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DialogEsmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/dialog-esms.php', 'dialog-esms');

        $this->app->singleton(DialogEsmsClient::class, function ($app) {
            return new DialogEsmsClient(
                http: $app->make(HttpFactory::class),
                config: $app['config']->get('dialog-esms'),
                events: $app->bound(Dispatcher::class) ? $app->make(Dispatcher::class) : null,
            );
        });

        $this->app->alias(DialogEsmsClient::class, SmsGateway::class);
        $this->app->alias(DialogEsmsClient::class, 'dialog-esms');
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerRoutes();
        $this->registerNotificationChannel();

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\CheckBalanceCommand::class,
                Console\SendTestMessageCommand::class,
                Console\EstimateCommand::class,
            ]);
        }
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/dialog-esms.php' => config_path('dialog-esms.php'),
        ], 'dialog-esms-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'dialog-esms-migrations');
    }

    protected function registerRoutes(): void
    {
        // Migrations load automatically only when logging is on; a host app
        // that does not want the tables should not have them created for it.
        if (config('dialog-esms.logging.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

        if (! config('dialog-esms.webhook.enabled', true)) {
            return;
        }

        // Both verbs. Dialog has been observed calling this as a GET, and a
        // POST-only route answers 405 and loses every receipt silently.
        Route::middleware(config('dialog-esms.webhook.middleware', ['api']))
            ->match(
                ['get', 'post'],
                config('dialog-esms.webhook.path', 'webhooks/dialog-esms'),
                DeliveryReceiptController::class,
            )
            ->name('dialog-esms.webhook');
    }

    protected function registerNotificationChannel(): void
    {
        if (! class_exists(ChannelManager::class)) {
            return;
        }

        $this->callAfterResolving(ChannelManager::class, function (ChannelManager $manager) {
            $manager->extend('dialog-esms', fn ($app) => new DialogEsmsChannel($app->make(SmsGateway::class)));
        });
    }

    /** @return array<int, string> */
    public function provides(): array
    {
        return [DialogEsmsClient::class, SmsGateway::class, 'dialog-esms'];
    }
}
