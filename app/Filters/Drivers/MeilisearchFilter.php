<?php

namespace App\Filters\Drivers;

use Illuminate\Support\Arr;

class MeilisearchFilter extends DriverFilter
{
    /** The filters the query keys name (DriverFilter::filterMethod()), joined for Meilisearch. */
    public function apply() : string
    {
        $filters = [];

        foreach ($this->receivedFilters() as $name => $value) {
            $method = $this->filterMethod($name, $value);

            if ($method !== null) {
                $filter = $this->{$method}($value);

                if (filled($filter)) {
                    $filters[] = $filter;
                }
            }
        }

        return Arr::join($filters, ' AND ');
    }
}
