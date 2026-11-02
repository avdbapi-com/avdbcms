<?php

namespace Tests\Support;

/**
 * Reads the rows of database/install/example.sql (a mysqldump: one "INSERT INTO `table` VALUES (...),(...);" per table,
 * the column order in its "CREATE TABLE") as PHP values, for the tests of the bundled backup. Strings come back
 * unescaped, NULL as null, numbers as their text.
 */
final class BundledSql
{
    public static function path(): string
    {
        return database_path('install/example.sql');
    }

    public static function read(): string
    {
        return (string) file_get_contents(self::path());
    }

    /** @return list<string> the tables the file inserts rows into, sorted */
    public static function tables(string $sql): array
    {
        preg_match_all('/^INSERT INTO `([^`]+)`/m', $sql, $matches);
        $tables = array_values(array_unique($matches[1]));
        sort($tables);

        return $tables;
    }

    /** @return list<string> the columns of $table, in the order of its CREATE TABLE */
    public static function columns(string $sql, string $table): array
    {
        if (preg_match('/CREATE TABLE `'.preg_quote($table, '/').'` \((.*?)\) ENGINE/s', $sql, $create) !== 1) {
            return [];
        }

        preg_match_all('/^\s*`([^`]+)`/m', $create[1], $columns);

        return $columns[1];
    }

    /** @return list<array<string, string|null>> the rows of $table, column => value */
    public static function rows(string $sql, string $table): array
    {
        $columns = self::columns($sql, $table);
        $start = strpos($sql, 'INSERT INTO `'.$table.'` VALUES');

        if ($start === false || $columns === []) {
            return [];
        }

        $rows = [];
        foreach (self::tuples(substr($sql, $start + strlen('INSERT INTO `'.$table.'` VALUES'))) as $values) {
            $rows[] = array_combine($columns, array_slice(array_pad($values, count($columns), null), 0, count($columns)));
        }

        return $rows;
    }

    /**
     * The value tuples of one INSERT, up to its closing ";" (a quote-aware scan: a ";" or ")" inside a text is text).
     *
     * @return list<list<string|null>>
     */
    private static function tuples(string $body): array
    {
        $tuples = [];
        $values = [];
        $current = '';
        $quoted = false;
        $wasQuoted = false;
        $depth = 0;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($quoted) {
                if ($char === '\\' && $i + 1 < $length) {
                    $next = $body[++$i];
                    $current .= ['n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", 'Z' => "\x1A", 'b' => "\x08"][$next] ?? $next;
                } elseif ($char === "'") {
                    $quoted = false;
                } else {
                    $current .= $char;
                }

                continue;
            }

            if ($char === ';' && $depth === 0) {
                break;
            }

            if ($char === "'") {
                $quoted = true;
                $wasQuoted = true;
            } elseif ($char === '(') {
                $depth++;
                $values = [];
                $current = '';
                $wasQuoted = false;
            } elseif ($char === ',' && $depth === 1) {
                $values[] = self::value($current, $wasQuoted);
                $current = '';
                $wasQuoted = false;
            } elseif ($char === ')' && $depth === 1) {
                $depth--;
                $values[] = self::value($current, $wasQuoted);
                $tuples[] = $values;
            } elseif ($depth === 1 && ! $wasQuoted) {
                $current .= $char;
            }
        }

        return $tuples;
    }

    private static function value(string $raw, bool $quoted): ?string
    {
        if ($quoted) {
            return $raw;
        }

        $raw = trim($raw);

        return strtoupper($raw) === 'NULL' ? null : $raw;
    }
}
