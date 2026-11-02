<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.2.3 (round 1b, R1R-04): the legacy admin views' Font Awesome fonts (resources/admin/lib/fa-solid.woff,
 * fa-regular.woff, fa-brands.woff, made with resources/admin/lib/_fa.scss by scripts/fontawesome/gen_admin_fa_subset.py)
 * are cut fonts: a Modified Version under the SIL OFL 1.1, which reserves the name "Font Awesome" for the original. Like
 * the site's cut fonts (gen_site_fa_subset.py), each carries its own name, keeps the copyright notice and carries the
 * license notice (name records 13 and 14) and where it comes from (10). Their glyphs are the icons _fa.scss draws: no
 * admin icon may come out blank.
 *
 * Read with PHP only (WOFF 1: zlib tables), like FontAwesomeSubsetTest reads the site's subset: re-run the generator
 * from the repository root (python scripts/fontawesome/gen_admin_fa_subset.py, it needs fontTools) when this fails.
 */
class AdminFontAwesomeSubsetTest extends TestCase
{
    /** file => [the font's own family name, its PostScript name, the largest size a cut may have] */
    private const FONTS = [
        'fa-solid.woff' => ['AVD Admin Icon Subset Solid', 'AVDAdminIconSubset-Solid', 40_000],
        'fa-regular.woff' => ['AVD Admin Icon Subset Regular', 'AVDAdminIconSubset-Regular', 8_000],
        'fa-brands.woff' => ['AVD Admin Brand Icon Subset', 'AVDAdminBrandIconSubset-Regular', 12_000],
    ];

    private const OFL_NOTICE = 'This Font Software is licensed under the SIL Open Font License, Version 1.1. This license is available with a FAQ at: https://openfontlicense.org';

    public function test_each_admin_cut_font_has_its_own_name_the_copyright_and_the_ofl_notice(): void
    {
        foreach (self::FONTS as $file => [$family, $postscript, $limit]) {
            $path = $this->root().'/resources/admin/lib/'.$file;
            $this->assertFileExists($path);
            $this->assertSame('wOFF', (string) file_get_contents($path, false, null, 0, 4), $file.': a WOFF file');
            $this->assertLessThan($limit, filesize($path), $file.': a cut, not the package\'s whole font');

            $names = $this->names($this->tables($path)['name'] ?? '');
            $this->assertNotSame([], $names, $file.': a name table');

            // the font's names (family, unique id, full name, PostScript name, typographic family / subfamily) never
            // use the Reserved Font Name
            foreach ([1, 3, 4, 6, 16, 17] as $id) {
                foreach ($names[$id] ?? [] as $text) {
                    $this->assertDoesNotMatchRegularExpression('/font\s*awesome/i', $text, sprintf('%s: name record %d "%s"', $file, $id, $text));
                }
            }
            $this->assertSame([$family], array_values(array_unique($names[1] ?? [])), $file.': family name');
            $this->assertSame([$family], array_values(array_unique($names[4] ?? [])), $file.': full name');
            $this->assertSame([$postscript], array_values(array_unique($names[6] ?? [])), $file.': PostScript name');

            // the copyright notice stays (OFL condition 2), the license notice and its address are there, and where the
            // font comes from
            $this->assertStringContainsString('Font Awesome', implode(' ', $names[0] ?? []), $file.': the copyright notice is kept');
            $this->assertSame([self::OFL_NOTICE], array_values(array_unique($names[13] ?? [])), $file.': OFL notice (13)');
            $this->assertSame(['https://openfontlicense.org'], array_values(array_unique($names[14] ?? [])), $file.': OFL address (14)');
            $this->assertStringContainsString('gen_admin_fa_subset.py', implode(' ', $names[10] ?? []), $file.': description (10)');
        }
    }

    public function test_every_icon_the_admin_subset_draws_has_a_glyph_in_its_fonts(): void
    {
        $scss = (string) file_get_contents($this->root().'/resources/admin/lib/_fa.scss');

        // the three faces, by family and weight, each with its file
        foreach (["'Font Awesome 6 Free'; font-style: normal; font-weight: 900;" => 'fa-solid.woff',
            "'Font Awesome 6 Free'; font-style: normal; font-weight: 400;" => 'fa-regular.woff',
            "'Font Awesome 6 Brands'; font-style: normal; font-weight: 400;" => 'fa-brands.woff'] as $face => $file) {
            $this->assertMatchesRegularExpression('/@font-face \{ font-family: '.preg_quote($face, '/').'[^}]*url\(\'\.\/'.preg_quote($file, '/').'\'\)/', $scss, $file);
        }

        $cmaps = [];
        foreach (array_keys(self::FONTS) as $file) {
            $cmaps[$file] = array_fill_keys($this->codePoints($this->tables($this->root().'/resources/admin/lib/'.$file)['cmap'] ?? ''), true);
            $this->assertNotSame([], $cmaps[$file], $file.': a cmap');
        }

        preg_match_all('/--fa: "\\\\([0-9a-f]+)";/', $scss, $m);
        $this->assertGreaterThan(100, count($m[1]), 'the subset\'s icon rules');
        $blank = [];
        foreach (array_unique($m[1]) as $hex) {
            $code = (int) hexdec($hex);
            if (! isset($cmaps['fa-solid.woff'][$code]) && ! isset($cmaps['fa-brands.woff'][$code])) {
                $blank[] = 'U+'.strtoupper($hex);
            }
        }
        $this->assertSame([], $blank, 'icons of resources/admin/lib/_fa.scss that no admin font draws: re-run "python scripts/fontawesome/gen_admin_fa_subset.py"');

        // Font Awesome Free's regular icons all have a solid glyph too; the brand font holds brand icons only
        $this->assertSame([], array_keys(array_diff_key($cmaps['fa-regular.woff'], $cmaps['fa-solid.woff'])));
        $this->assertSame([], array_keys(array_intersect_key($cmaps['fa-brands.woff'], $cmaps['fa-solid.woff'])));
    }

    /** @return array<string, string> the font's tables (tag => bytes), WOFF 1 (zlib) */
    private function tables(string $path): array
    {
        $data = (string) file_get_contents($path);
        $count = unpack('n', substr($data, 12, 2))[1];
        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = unpack('a4tag/Noffset/Ncomp/Norig', substr($data, 44 + 20 * $i, 16));
            $raw = substr($data, $entry['offset'], $entry['comp']);
            $tables[$entry['tag']] = $entry['comp'] < $entry['orig'] ? (string) zlib_decode($raw) : $raw;
            $this->assertSame($entry['orig'], strlen($tables[$entry['tag']]), $path.' '.$entry['tag']);
        }

        return $tables;
    }

    /** @return array<int, list<string>> name id => its texts (Unicode and Macintosh records) */
    private function names(string $table): array
    {
        if (strlen($table) < 6) {
            return [];
        }
        ['count' => $count, 'strings' => $strings] = unpack('nformat/ncount/nstrings', $table);
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $r = unpack('nplatform/nencoding/nlanguage/nid/nlength/noffset', substr($table, 6 + 12 * $i, 12));
            $bytes = substr($table, $strings + $r['offset'], $r['length']);
            $names[$r['id']][] = $r['platform'] === 1 ? $bytes : (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        }

        return $names;
    }

    /** @return list<int> the code points the font maps to a glyph (cmap formats 4 and 12) */
    private function codePoints(string $cmap): array
    {
        $points = [];
        $count = unpack('n', substr($cmap, 2, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            ['platform' => $platform, 'encoding' => $encoding, 'offset' => $offset] = unpack('nplatform/nencoding/Noffset', substr($cmap, 4 + 8 * $i, 8));
            if (! (($platform === 3 && in_array($encoding, [1, 10], true)) || $platform === 0)) {
                continue;
            }
            $format = unpack('n', substr($cmap, $offset, 2))[1];
            if ($format === 4) {
                $segments = unpack('n', substr($cmap, $offset + 6, 2))[1] / 2;
                $ends = $offset + 14;
                $starts = $ends + 2 * $segments + 2;
                $deltas = $starts + 2 * $segments;
                $ranges = $deltas + 2 * $segments;
                for ($s = 0; $s < $segments; $s++) {
                    $end = unpack('n', substr($cmap, $ends + 2 * $s, 2))[1];
                    $start = unpack('n', substr($cmap, $starts + 2 * $s, 2))[1];
                    $delta = unpack('n', substr($cmap, $deltas + 2 * $s, 2))[1];
                    $range = unpack('n', substr($cmap, $ranges + 2 * $s, 2))[1];
                    for ($c = $start; $c <= $end && $c !== 0xFFFF; $c++) {
                        if ($range === 0) {
                            $glyph = ($c + $delta) & 0xFFFF;
                        } else {
                            $at = $ranges + 2 * $s + $range + 2 * ($c - $start);
                            $glyph = unpack('n', substr($cmap, $at, 2))[1];
                            $glyph = $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
                        }
                        if ($glyph !== 0) {
                            $points[] = $c;
                        }
                    }
                }
            } elseif ($format === 12) {
                $groups = unpack('N', substr($cmap, $offset + 12, 4))[1];
                for ($g = 0; $g < $groups; $g++) {
                    ['first' => $first, 'last' => $last] = unpack('Nfirst/Nlast', substr($cmap, $offset + 16 + 12 * $g, 8));
                    $points = array_merge($points, range($first, $last));
                }
            }
        }
        $points = array_values(array_unique($points));
        sort($points);

        return $points;
    }

    private function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }
}
