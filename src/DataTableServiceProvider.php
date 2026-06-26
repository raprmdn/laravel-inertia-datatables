<?php

namespace Raprmdn\DataTables;

use Illuminate\Support\ServiceProvider;

class DataTableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/inertia-datatables.php',
            'inertia-datatables'
        );

        $this->app->singleton('inertia-datatables', function () {
            return new DataTableManager();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/inertia-datatables.php' => config_path('inertia-datatables.php'),
        ], 'inertia-datatables-config');
    }
}
