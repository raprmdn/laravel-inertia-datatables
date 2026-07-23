<?php

namespace Raprmdn\DataTables\Support;

enum FilterStrategy
{
    case Exact;
    case JsonContains;
    case Custom;
}
