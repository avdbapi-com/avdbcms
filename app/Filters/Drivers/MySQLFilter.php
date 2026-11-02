<?php

namespace App\Filters\Drivers;

use Illuminate\Database\Eloquent\Builder;

class MySQLFilter extends DriverFilter
{
    /**
     * The field to use for date filter.
     *
     * @var string
     */
    protected string $dateField = 'created_at';

    /**
     * The builder instance.
     *
     * @var Builder
     */
    protected Builder $builder;

    /**
     * Each query key that names a filter of this class (DriverFilter::filterMethod(): its own public methods, with a
     * value that fits) narrows the query; every other key is left alone, so a crafted address lists as usual.
     */
    public function apply(Builder $builder) : Builder
    {
        $this->builder = $builder;

        foreach ($this->receivedFilters() as $name => $value) {
            $method = $this->filterMethod($name, $value);

            if ($method !== null) {
                $this->builder = $builder;
                $this->{$method}($value);
            }
        }

        return $builder;
    }
}
