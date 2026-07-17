<?php

namespace Tests\Feature;

use Illuminate\Support\ServiceProvider;
use Raprmdn\DataTables\DataTableManager;
use Raprmdn\DataTables\DataTableServiceProvider;
use Raprmdn\DataTables\Facades\DataTable;
use Tests\TestCase;

class PackageRegistrationTest extends TestCase
{
    public function test_service_provider_registers_manager_and_default_config(): void
    {
        $this->assertInstanceOf(
            DataTableServiceProvider::class,
            $this->app->getProvider(DataTableServiceProvider::class)
        );
        $this->assertInstanceOf(DataTableManager::class, $this->app->make('inertia-datatables'));
        $this->assertSame('search', config('inertia-datatables.query_params.search'));
        $this->assertSame(100, config('inertia-datatables.pagination.max_per_page'));
    }

    public function test_facade_resolves_the_manager_binding(): void
    {
        $this->assertInstanceOf(DataTableManager::class, DataTable::getFacadeRoot());
        $this->assertSame($this->app->make('inertia-datatables'), DataTable::getFacadeRoot());
    }

    public function test_service_provider_registers_config_publish_path(): void
    {
        $paths = ServiceProvider::pathsToPublish(
            DataTableServiceProvider::class,
            'inertia-datatables-config'
        );

        $this->assertCount(1, $paths);
        $this->assertSame(
            realpath(dirname(__DIR__, 2) . '/config/inertia-datatables.php'),
            realpath(array_key_first($paths))
        );
        $this->assertSame(config_path('inertia-datatables.php'), current($paths));
    }
}
