<?php

namespace App\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator as LaravelCursorPaginator;
use Illuminate\Support\Str;

/**
 * "Load more" pages that a hand-made or outdated cursor cannot break. cursorPaginate() trusts every cursor it can
 * decode: one that is not a JSON object, has no _pointsToNextItems flag or lacks one of the list's order columns stops
 * the request with an exception (HTTP 500). Such cursors come from hand-made links, from an app that keeps a cursor when
 * its sort changes, and from open tabs and cached first pages kept across an update that renamed an order column (1.2.3:
 * the most-commented count, PublicVideoFeedService::RENAMED_CURSOR_KEYS). A cursor that does not fit gives the first
 * page, as a cursor Laravel cannot decode always did.
 *
 * An order column can be empty (NULL): a public video an older version made public without publishing it has no publish
 * date (Admin > Videos > Unban and Make public up to 1.2.2). SQL orders NULL before every value, so first going up and
 * last going down. Laravel's own conditions compare each column with "<" or ">", which NULL never passes: the page after
 * a dated video skipped every undated one (Newest never listed them after its first page), and a cursor that holds NULL
 * (a page that ended on such a video) stopped the request ("Illegal operator and value combination": HTTP 500 for every
 * visitor of Most viewed and Trending in 1.2.2). Every cursor pages with conditions built here instead (paginateAfter()),
 * each row once and NULL in its place.
 */
final class SafeCursor
{
    /**
     * The cursor argument of cursorPaginate() for the first page: a string it cannot decode counts as no cursor, while
     * null would make it read the request's cursor again.
     */
    public const FIRST_PAGE = '';

    /**
     * The request's "cursor" from the query string only, as text: '' when there is none or a hand-made cursor[]= made it
     * an array. Lists cached by their query string (the guest feeds) page from this value, so a cursor sent another way
     * (a GET body) can never put a later page under the first page's cache key.
     */
    public static function fromQuery(Request $request): string
    {
        $cursor = $request->query('cursor');

        return is_string($cursor) ? $cursor : '';
    }

    /**
     * One page of $query from $cursor (the raw value the request sent); the first page when the cursor does not fit.
     *
     * @param  array<string, string>  $renamed  a cursor key an earlier release wrote => the key the query orders by now
     */
    public static function paginate(EloquentBuilder|Relation|QueryBuilder $query, int $perPage, mixed $cursor, array $renamed = []): CursorPaginator
    {
        $cursor = self::fitting($query, $cursor, $renamed);

        if ($cursor === null) {
            return $query->cursorPaginate($perPage, ['*'], 'cursor', self::FIRST_PAGE);
        }

        $base = self::base($query);

        // No list of the site pages a union (or a plain query without an order): such a query keeps Laravel's own
        // conditions, and a cursor that holds NULL gets its first page, as before.
        if ($base->unions || $base->unionOrders || ($query instanceof QueryBuilder && ! $base->orders)) {
            return $query->cursorPaginate($perPage, ['*'], 'cursor', self::holdsNull($query, $cursor) ? self::FIRST_PAGE : $cursor);
        }

        return self::paginateAfter($query, $perPage, $cursor);
    }

    /**
     * $cursor when $query can page from it (it carries every column the query orders by, after $renamed), else null.
     *
     * @param  array<string, string>  $renamed
     */
    private static function fitting(EloquentBuilder|Relation|QueryBuilder $query, mixed $cursor, array $renamed = []): ?Cursor
    {
        $cursor = self::decode($cursor);

        if ($cursor === null) {
            return null;
        }

        $parameters = $cursor->toArray();
        unset($parameters['_pointsToNextItems']);

        foreach ($renamed as $from => $to) {
            if (array_key_exists($from, $parameters) && ! array_key_exists($to, $parameters)) {
                $parameters[$to] = $parameters[$from];
                unset($parameters[$from]);
            }
        }

        foreach (self::orderColumns($query) as $column) {
            if (! array_key_exists($column, $parameters)) {
                return null;
            }
        }

        return new Cursor($parameters, $cursor->pointsToNextItems());
    }

    /**
     * Cursor::fromEncoded() without its warnings: a cursor Laravel could have written (base64 of a JSON object with a
     * true / false _pointsToNextItems), else null.
     */
    public static function decode(mixed $cursor): ?Cursor
    {
        if (! is_string($cursor) || $cursor === '') {
            return null;
        }

        $parameters = json_decode((string) base64_decode(str_replace(['-', '_'], ['+', '/'], $cursor)), true);

        if (! is_array($parameters) || ! is_bool($parameters['_pointsToNextItems'] ?? null)) {
            return null;
        }

        $pointsToNextItems = $parameters['_pointsToNextItems'];
        unset($parameters['_pointsToNextItems']);

        return new Cursor($parameters, $pointsToNextItems);
    }

    /**
     * The columns cursorPaginate() reads from the cursor, taken as ensureOrderForCursorPagination() takes them: the
     * union's orders when there are any, else the query's, those with a direction; with no order at all, Eloquent orders
     * by the model's key (a query builder without an order cannot be cursor-paged at all).
     *
     * @return list<string>
     */
    private static function orderColumns(EloquentBuilder|Relation|QueryBuilder $query): array
    {
        $base = self::base($query);
        $orders = $base->unionOrders ?: ($base->orders ?: []);

        if ($orders === []) {
            return $query instanceof QueryBuilder
                ? []
                : [($query instanceof Relation ? $query->getRelated() : $query->getModel())->getQualifiedKeyName()];
        }

        $columns = [];

        foreach ($orders as $order) {
            if (array_key_exists('direction', $order) && is_string($order['column'] ?? null)) {
                $columns[] = $order['column'];
            }
        }

        return $columns;
    }

    /** Whether $cursor holds an empty value (NULL) for one of the columns $query orders by. */
    private static function holdsNull(EloquentBuilder|Relation|QueryBuilder $query, Cursor $cursor): bool
    {
        foreach (self::orderColumns($query) as $column) {
            if ($cursor->parameter($column) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The page after (or, going back, before) $cursor. The conditions are built here, with the place MySQL, MariaDB and
     * SQLite give NULL in an order: before every value, so first going up and last going down. Laravel then pages the
     * narrowed list from its start, one row more than a page (that row tells whether more follow), and the page is made
     * with the cursor it came from, so its links go on like those of any other page.
     */
    private static function paginateAfter(EloquentBuilder|Relation|QueryBuilder $query, int $perPage, Cursor $cursor): CursorPaginator
    {
        $query = clone $query;
        $base = self::base($query);

        if (! $base->orders) {
            // Eloquent's own order for a list without one (enforceOrderBy()): its key, going up.
            $query->orderBy(($query instanceof Relation ? $query->getRelated() : $query->getModel())->getQualifiedKeyName());
        }

        if ($cursor->pointsToPreviousItems()) {
            // The rows before the cursor: the order turns around here, and the page turns its rows back.
            $base->orders = array_map(fn (array $order): array => isset($order['direction'])
                ? ['direction' => $order['direction'] === 'asc' ? 'desc' : 'asc'] + $order
                : $order, $base->orders);
        }

        $orders = array_values(array_filter($base->orders, fn (array $order): bool => isset($order['direction']) && is_string($order['column'] ?? null)));
        $query->where(fn ($builder) => self::whereAfter($builder, $base, $orders, $cursor, 0));
        $page = $query->cursorPaginate($perPage + 1, ['*'], 'cursor', self::FIRST_PAGE);

        return Container::getInstance()->makeWith(LaravelCursorPaginator::class, [
            'items' => $page->getCollection(),
            'perPage' => $perPage,
            'cursor' => $cursor,
            'options' => $page->getOptions(),
        ]);
    }

    /**
     * The rows after $cursor in $orders from column $i on, the columns before $i holding the cursor's values: a later
     * value in this column, or the same one and a later row by the next columns. NULL comes before every value.
     *
     * @param  list<array{column: string, direction: string}>  $orders
     */
    private static function whereAfter(EloquentBuilder|QueryBuilder $builder, QueryBuilder $base, array $orders, Cursor $cursor, int $i): void
    {
        $column = self::originalColumn($base, $orders[$i]['column']);
        $value = $cursor->parameter($orders[$i]['column']);
        $up = $orders[$i]['direction'] === 'asc';
        $nullable = ! self::neverNull($base, $column);

        $builder->where(function ($builder) use ($column, $value, $up, $nullable): void {
            if ($value === null) {
                // going up every value comes after NULL, going down nothing does
                $up ? $builder->whereNotNull($column) : $builder->whereRaw('0 = 1');
            } elseif ($up) {
                $builder->where($column, '>', $value);
            } elseif ($nullable) {
                $builder->where($column, '<', $value)->orWhereNull($column);
            } else {
                $builder->where($column, '<', $value);
            }
        });

        if ($i < count($orders) - 1) {
            $builder->orWhere(function ($builder) use ($base, $orders, $cursor, $i, $column, $value): void {
                $value === null ? $builder->whereNull($column) : $builder->where($column, '=', $value);
                $builder->where(fn ($builder) => self::whereAfter($builder, $base, $orders, $cursor, $i + 1));
            });
        }
    }

    /**
     * Whether an order column can never be NULL: a count (withCount()'s "(select count(*) ...)"). Its rows need no NULL
     * branch, which would run the subquery once more for every row (Most commented: a quarter slower on MariaDB).
     */
    private static function neverNull(QueryBuilder $base, string|Expression $column): bool
    {
        return $column instanceof Expression
            && preg_match('/^\(\s*select\s+count\(/i', (string) $column->getValue($base->getGrammar())) === 1;
    }

    /**
     * What a cursor key stands for, as getOriginalColumnNameForCursorPagination() finds it: a key that names an alias of
     * the select list ("(select count(*) ...) as public_comments_count") is compared by what the alias stands for.
     */
    private static function originalColumn(QueryBuilder $base, string $column): string|Expression
    {
        foreach ($base->getColumns() as $selected) {
            if (is_string($selected) && ($position = strripos($selected, ' as ')) !== false) {
                $alias = substr($selected, $position + 4);

                if ($column === $alias || $base->getGrammar()->wrap($column) === $alias) {
                    $original = substr($selected, 0, $position);

                    return Str::contains($original, ['(', ')']) ? new Expression($original) : $original;
                }
            }
        }

        return $column;
    }

    private static function base(EloquentBuilder|Relation|QueryBuilder $query): QueryBuilder
    {
        return match (true) {
            $query instanceof Relation => $query->getBaseQuery(),
            $query instanceof EloquentBuilder => $query->getQuery(),
            default => $query,
        };
    }
}
