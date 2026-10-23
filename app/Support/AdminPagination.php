<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AdminPagination
{
    /**
     * @return array<int, int>
     */
    public static function perPageOptions(): array
    {
        return [15, 50, 100, 200, 500, 1000];
    }

    public static function perPage(?Request $request = null, int $default = 15): int
    {
        $request ??= request();
        $options = self::perPageOptions();
        $fallback = max(1, $default);
        $value = (int) $request->query('per_page', $fallback);

        return in_array($value, $options, true) ? $value : $fallback;
    }

    public static function collection(iterable $items, int $perPage = 25, string $pageName = 'page'): LengthAwarePaginator
    {
        $perPage = self::perPage(null, $perPage);

        $collection = $items instanceof Collection
            ? $items->values()
            : collect($items)->values();

        $page = max(1, (int) LengthAwarePaginator::resolveCurrentPage($pageName));

        return new LengthAwarePaginator(
            $collection->slice(($page - 1) * $perPage, $perPage)->values(),
            $collection->count(),
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
    }
}
