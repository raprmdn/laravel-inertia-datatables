<?php

namespace Raprmdn\DataTables\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Raprmdn\DataTables\DataTableManager
 */
class DataTable extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'inertia-datatables';
    }
}
