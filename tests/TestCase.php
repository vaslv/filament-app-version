<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion\Tests;

use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Vaslv\FilamentAppVersion\AppVersionServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            AppVersionServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.debug', false);
    }

    /**
     * Writes a temporary composer.json into the Testbench skeleton's base_path
     * and returns the path.
     *
     * The resolver needs a real file on disk: there is no point mocking
     * file_get_contents, we check exactly the behaviour that will run in
     * production.
     */
    protected function writeComposerJson(string $contents): string
    {
        $path = $this->app->basePath('composer.json');

        file_put_contents($path, $contents);

        $this->beforeApplicationDestroyed(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }
}
