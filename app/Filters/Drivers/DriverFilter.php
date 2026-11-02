<?php

namespace App\Filters\Drivers;

use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

abstract class DriverFilter
{
    /**
     * Public methods of the drivers themselves: never a filter, whatever a query key says ("?apply=1" called apply()
     * with a string and answered 500).
     */
    private const DRIVER_METHODS = ['apply', 'receivedfilters', 'filtermethod'];

    /** @var array<class-string, array<string, array{0: string, 1: ?string}>> class => lower-case name => [method, type] */
    private static array $filterMethods = [];

    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function receivedFilters(): array
    {
        $filters = $this->filters ?: request()->query();

        $clear = request()->exists('clear');

        return $clear ? [] : $filters;
    }

    /**
     * The filter a query key names, when its value fits it; null otherwise (the key is left alone). Only the public
     * methods the filter class itself declares (or takes from its traits) are filters: never the driver's own (apply,
     * receivedFilters), a private helper ("?day=x", "?list_date_from=1" answered 500) or a static one. A value must
     * fit the filter's parameter: a list ("?search[]=x") for a text filter is no filter, so a crafted address gets the
     * plain list instead of a server error.
     */
    protected function filterMethod(mixed $name, mixed $value): ?string
    {
        if (! is_string($name) || $name === '' || strlen($name) > 64) {
            return null;
        }

        [$method, $type] = self::methodsOf(static::class)[strtolower(Str::camel($name))] ?? [null, null];

        if ($method === null) {
            return null;
        }

        return match ($type) {
            'string' => is_string($value) ? $method : null,
            'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) ? $method : null,
            'array' => is_array($value) ? $method : null,
            default => is_scalar($value) || $value === null ? $method : null,
        };
    }

    /**
     * The filters of a class: its public, non-static methods that take one value, declared below the drivers.
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    private static function methodsOf(string $class): array
    {
        if (isset(self::$filterMethods[$class])) {
            return self::$filterMethods[$class];
        }

        $methods = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $declaring = $method->getDeclaringClass()->getName();
            $name = strtolower($method->getName());

            if ($method->isStatic() || $method->isAbstract() || str_starts_with($name, '__')
                || in_array($name, self::DRIVER_METHODS, true)
                || in_array($declaring, [self::class, MySQLFilter::class, MeilisearchFilter::class], true)
                || $method->getNumberOfParameters() < 1 || $method->getNumberOfRequiredParameters() > 1) {
                continue;
            }

            $type = $method->getParameters()[0]->getType();
            $methods[$name] = [$method->getName(), $type instanceof ReflectionNamedType && $type->getName() !== 'mixed' ? $type->getName() : null];
        }

        return self::$filterMethods[$class] = $methods;
    }
}
