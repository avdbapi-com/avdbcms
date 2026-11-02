<?php

namespace App\Support\Member;

/**
 * Member danger tokens under the OWNER's own colours (member design 3.1a, revision 3).
 *
 * The generated map (resources/sass/member/_danger.scss) is static per theme block. When the owner sets a Main colour
 * (or another owner colour, or "Pure black in dark mode"), ThemeTokens::ownerOverrides() derives the brand again per
 * request; this class re-runs the map's rule on those derived tokens and returns one more CSS block with the four
 * member danger tokens, so Save and Delete never become one colour:
 *   1. the theme's own danger when it is >= dE00 22 from the brand ink and fill and its fill >= 20 from every stop of
 *      the primary button (also where the static map had replaced it);
 *   2. else the NEUTRAL danger (the look's text colour, an inverse fill) when it passes the same checks;
 *   3. else the first colour of a short pre-sorted candidate list (CANDIDATES) that is
 *      >= 22 from the brand, >= 20 from the button, >= 4.5:1 on every page surface and carries a >= 4.5:1 label;
 *   4. else the neutral danger.
 * Danger is never the WARNING colour either ("Upload failed" next to "Unlisted", a danger alert
 * next to "Unsaved changes"). Every step also requires the danger ink and fill to be >= dE00 22 from the warning ink
 * (WARN_DE; a second pass over the candidates accepts 20, WARN_BAR, before the neutral fallback), the candidates near the
 * warning are skipped, and the red family is tried first whenever the warning is amber or orange (else an orange next to
 * an amber warning was picked: Velvet Lounge dark with "Pure black" got #e88a16, dE00 6.6 from #f59e0b; Imperial Salon
 * dark with any red Main colour #fc8630, 10.1-12.2).
 * Pure PHP, no Laravel: ThemeTokens::ownerOverrides() calls css() for each mode it derives and appends the result to the
 * owner CSS it already caches (ThemeTokens::ENGINE_VERSION went up so a 1.2.2 cache entry is never served).
 */
final class DangerTokens
{
    public const MIN_DE = 22.0;

    public const BAR = 20.0;

    /** Danger vs the warning ink: the aim in every step, and the floor a candidate may still meet in the second pass. */
    public const WARN_DE = 22.0;

    public const WARN_BAR = 20.0;

    /** Vivid in-gamut reds and oranges per mode (computed in advance), closest to a typical danger first. */
    private const CANDIDATES = [
        'dark' => [
            'red' => [
                '#ff6063', '#f95864', '#fe6b71', '#f96472', '#fe5454', '#f25459', '#ef6d6c', '#fc6e67', '#ef635d', '#fc4761',
                '#fb556f', '#ec5968', '#fb7876', '#e36262', '#fb6458', '#ed4c5a', '#fd767f', '#ee7779', '#e26d6f', '#eb6575',
                '#f76f7f', '#fc3653', '#ec7063', '#ea514f', '#ec7971', '#ef594e', '#f9627d', '#e06f67', '#f83e45', '#ee4965',
                '#fd4943', '#dc666f', '#e06559', '#f54d70', '#f97b6c', '#df7875', '#fa8283', '#f26c58', '#fe2f60', '#e54950',
                '#e0777c', '#e97081', '#d56361', '#de5a6b', '#fa5a47', '#e55e75', '#ef3958', '#f33347', '#f8847b', '#e04d5e',
                '#eb8282', '#d36e6b', '#d65858', '#fb818c', '#f76e89', '#fd1151', '#dd7a6d', '#dd715f', '#e97c69', '#ec818a',
                '#e24e45', '#e65644', '#cf5d61', '#d26659', '#d17064', '#f96187', '#da717f', '#fe6e52', '#ce676d', '#e98577',
                '#ef3c3a', '#e75073', '#e84166', '#d45b4f', '#f73a6f', '#f44738', '#ea694e', '#f82362', '#f78d8d', '#d9536b',
                '#f54c7c', '#f57e64', '#f68773', '#fb8095', '#f1583d', '#e67b8d', '#e86f8a', '#fd1f2f', '#ce6674', '#e45d7f',
                '#f58f82', '#c6645d', '#da7c67', '#d7634b', '#f88b98', '#fe492f', '#d97086', '#f66e93', '#e77556', '#f56b47',
                '#e68771', '#ce725d', '#de533a', '#fb7b58', '#e16645', '#e83f72', '#c46656', '#d85274', '#fb5a34', '#d46f53',
                '#ce657c', '#e64f7e', '#ec8466', '#e57a95', '#f6397c', '#fa7f9e', '#f81d70', '#ea452c', '#f15b8d', '#f2917b',
                '#e76f93', '#f78ba0', '#e85532', '#f7311e', '#db6489', '#d8708e', '#df724d', '#f2784f', '#fb4088', '#ce6042',
                '#f88f70', '#f44822', '#ec693d', '#ff6e3f', '#f46e9d', '#fe5898', '#dd8465', '#d17a5b', '#d9633c', '#fe8359',
                '#c16950', '#d7527e', '#fe9c85', '#cc6b4a', '#cc6584', '#e73f7e', '#f15828', '#e980a2', '#f68ba9', '#e98f6f',
                '#e44f89', '#f61c7d', '#e4709c', '#ef5b98', '#e05e92', '#f92d05', '#de5327', '#d67096', '#fd4b12', '#ff489a',
            ],
            'orange' => [
                '#fc8630', '#fc861a', '#f97e30', '#f38c37', '#f18c46', '#ef7c25', '#fb8f4f', '#ef7c07', '#e6812c', '#fe9750',
                '#f38c24', '#fd710b', '#fe8445', '#e87f40', '#e8914d', '#e48b3a', '#f77631', '#ed7325', '#f09644', '#e68216',
                '#df8446', '#f59c57', '#f18b57', '#e6925a', '#e27118', '#fe9661', '#f59424', '#fb7c45', '#ef9562', '#da7721',
                '#fb6811', '#d6813e', '#e88a16', '#fca14e', '#e57840', '#e6933b', '#d7812f', '#d87732', '#e69a5a', '#da9050',
                '#f8a269', '#da7703', '#ea6b27', '#df8456', '#ee9f51', '#dc7f02', '#f46d32', '#d67840', '#d58a3d', '#e29366',
                '#dd9661', '#e0691a', '#d48a59', '#f2a564', '#dd9752', '#f49268', '#e9a06b', '#fd8357', '#ee9e70', '#ce7d46',
                '#ea9217', '#e2794c', '#dc9033', '#f1794d', '#f69c25', '#fe6f3d', '#e49c4a', '#dd8702', '#faaa5c', '#ca7734',
                '#ee5d00', '#cd7e26', '#f95e16', '#d08357', '#cb7724', '#c88041', '#d66708', '#e6906b', '#d86d36', '#d3794c',
                '#c98750', '#f4a548', '#dd9e62', '#f5ab75', '#f08b67', '#f29b75', '#faa87a', '#cd6d14', '#cc8e53', '#cc6d28',
                '#feb06e', '#e3815d', '#ca7644', '#e76228', '#f16533', '#cd8e46', '#eba85f', '#dd601c', '#d4805c', '#e46e44',
                '#cd7f0e', '#cf9556', '#d56f43', '#ffa680', '#efae71', '#bf7c48', '#c08449', '#bf7d39', '#bf743a', '#c2843c',
                '#d77653', '#c57203', '#d35e0c', '#fcb25e', '#eb5307', '#c67750', '#c96527', '#c26a1e', '#f6541b', '#c76f43',
                '#c36a03', '#bc7546', '#e45a29', '#d8643a', '#bb6f27', '#fbb97b', '#bc6d38', '#da581d', '#bd7616', '#cf6131',
                '#ca7556', '#b78042', '#cb6c49', '#b4723f', '#b37330', '#bb6d46', '#e9480e', '#bf6b4c',
            ],
        ],
        'light' => [
            'red' => [
                '#be252f', '#c21c24', '#b91930', '#c62939', '#d1042a', '#b02d2a', '#bc3933', '#b13938', '#b11526', '#ca3338',
                '#c11d3a', '#cf2c2d', '#b4362a', '#ac3138', '#ba1a1a', '#b83c41', '#c72a23', '#d90835', '#a52e2f', '#bd4441',
                '#b40631', '#cd2d43', '#a82a21', '#dc0023', '#b04541', '#c04133', '#d30016', '#a33c34', '#a6192b', '#a32022',
                '#d23642', '#de1f33', '#ca4241', '#b8311e', '#c82244', '#bb0e3b', '#ac3d44', '#bf404a', '#c41b10', '#ad0211',
                '#ae2e41', '#ac3f2b', '#ad4839', '#cd3f31', '#c14b41', '#ac2515', '#a34641', '#a0323b', '#c53d27', '#e20f40',
                '#da2c26', '#98302f', '#9b392c', '#b70205', '#bb2747', '#d02b19', '#d5423b', '#a81135', '#bd4e4e', '#a1483a',
                '#d4314d', '#e03242', '#9c1e18', '#973c38', '#aa494c', '#e22e31', '#ae504a', '#9e3f43', '#bc3a1d', '#c83450',
                '#aa361b', '#9a2f1e', '#b5260a', '#d6124b', '#9b1c2f', '#ad3b4c', '#990b1e', '#c64553', '#da3a4c', '#ed033a',
                '#b0462b', '#b24f3a', '#ca4d4f', '#ac5244', '#dc0207', '#c72a0b', '#a50204', '#bd0245', '#c65242', '#9e4129',
                '#a13043', '#902e23', '#c2554f', '#c22c51', '#93343a', '#ae2b49', '#8f392d', '#b84656', '#9e2b12', '#d14831',
                '#c24b2e', '#94402e', '#b65356', '#e9154b', '#a81800', '#ec121d', '#9f3d4a', '#ae3e1b', '#ce3f1f', '#dd3823',
                '#8c2527', '#ab4754', '#9e4b34', '#b15845', '#b83206', '#a9083e', '#923720', '#9c1838', '#db3657', '#c4515b',
                '#8e1022', '#bf431c', '#a72d06', '#ce395a', '#a84d2f', '#8b181a', '#8e2415', '#9c3919', '#da2e0c', '#a9553d',
                '#943341', '#b85c4d', '#b44e2c', '#dd1a56', '#b43053', '#8f2032', '#b6525d', '#b01248', '#b33f59', '#c25639',
                '#ca3605', '#842f25', '#cf2359', '#971500', '#930202', '#ef0209', '#914228', '#a12e4b', '#b45738', '#a54156',
                '#cc4861', '#952907', '#89040b', '#81262a', '#c22a5b', '#81191e', '#b14c60', '#c6532d', '#bf4a63', '#b55f47',
            ],
            'orange' => [
                '#9e4200', '#a04b14', '#974808', '#963f0e', '#95491a', '#98501b', '#a43d0e', '#ad4a12', '#a74f04', '#894010',
                '#a15323', '#9a4521', '#8a480d', '#965128', '#983303', '#883700', '#8c4621', '#88491c', '#b15112', '#ab4b22',
                '#8e3c18', '#b5420d', '#a9571a', '#9b3a18', '#9a4e2d', '#975103', '#915423', '#ad3f19', '#a9562b', '#a62f04',
                '#aa5603', '#7f3f02', '#a15b21', '#813c18', '#904327', '#a15b31', '#7c3801', '#b73303', '#89511b', '#b25a23',
                '#8f300e', '#ba541d', '#b45912', '#bc5308', '#7e4712', '#b94a22', '#82320e', '#ad5332', '#905511', '#854c04',
                '#b7552b', '#c44b00', '#ac5e1b', '#9a5804', '#85391e', '#783101', '#753c0a', '#a95e38', '#9a5f2a', '#c43e0f',
                '#925c23', '#ba5d2c', '#ad5e03', '#b56124', '#a46223', '#842605', '#c35627', '#ad5b3e', '#845417', '#ad652b',
                '#b75e38', '#ad653a', '#935c13', '#794a0c', '#cd4d12', '#772804', '#793016', '#703f04', '#6c3203', '#a56212',
                '#702e0b', '#bf5834', '#af651c', '#9c5f06', '#9d662c', '#a66933', '#d6430f', '#af6504', '#a76926', '#6e260d',
                '#a86914',
            ],
        ],
    ];

    /**
     * The CSS block for one mode: (0,4,1), so it beats every rule of the static map ((0,2,1)-(0,3,1)) wherever the
     * member CSS is printed.
     *
     * @param  array<string, string>  $tokens  the tokens ThemeTokens::derive() gives for this mode under the owner's palette
     */
    public static function css(array $tokens, string $mode): string
    {
        $vars = self::decide($tokens, $mode)['vars'];
        $body = [];

        foreach ($vars as $name => $value) {
            $body[] = $name.':'.$value;
        }

        return 'html:root[data-avd-theme][data-avd-flavor][data-bs-theme="'.($mode === 'light' ? 'light' : 'dark').'"]{'.implode(';', $body).'}';
    }

    /**
     * @param  array<string, string>  $tokens
     * @return array{strategy: string, vars: array<string, string>, ink: array{0:int,1:int,2:int}, fill: array{0:int,1:int,2:int}, label: array{0:int,1:int,2:int}}
     */
    public static function decide(array $tokens, string $mode): array
    {
        $bg = self::colour($tokens['--avd-bg'] ?? '#000000') ?? [0, 0, 0, 1.0];
        $bg = [$bg[0], $bg[1], $bg[2]];
        $surfaces = [$bg];

        foreach (['--avd-surface-1', '--avd-surface-2', '--avd-surface-solid'] as $name) {
            $c = isset($tokens[$name]) ? self::colour($tokens[$name]) : null;

            if ($c !== null) {
                $surfaces[] = self::over($c, $bg);
            }
        }

        $s1 = $surfaces[1] ?? $bg;
        $brandInk = self::over(self::colour($tokens['--avd-brand-ink'] ?? '') ?? [255, 0, 0, 1.0], $bg);
        $brandFill = self::over(self::colour($tokens['--avd-brand-fill'] ?? '') ?? [255, 0, 0, 1.0], $s1);
        $buttons = array_map(fn (array $c): array => self::over($c, $s1), self::colours($tokens['--avd-btn-bg'] ?? ''));
        $text = self::over(self::colour($tokens['--avd-text'] ?? '#ffffff') ?? [255, 255, 255, 1.0], $bg);
        $danger = self::colour($tokens['--avd-danger'] ?? '');
        $dangerFill = self::colour($tokens['--avd-danger-fill'] ?? '');
        $onDanger = self::colour($tokens['--avd-on-danger'] ?? '#ffffff') ?? [255, 255, 255, 1.0];
        $warningColour = self::colour($tokens['--avd-warning'] ?? '');
        $warning = $warningColour !== null ? self::over($warningColour, $bg) : null;

        $passes = function (array $ink, array $fill, float $minButton, float $minWarning = self::WARN_DE) use ($brandInk, $brandFill, $buttons, $warning): bool {
            foreach ([[$brandInk, $ink], [$brandFill, $ink], [$brandFill, $fill]] as [$a, $b]) {
                if (self::deltaE($a, $b) < self::MIN_DE) {
                    return false;
                }
            }

            if (self::deltaE($brandInk, $fill) < self::BAR) {
                return false;
            }

            foreach ($buttons as $stop) {
                if (self::deltaE($stop, $fill) < $minButton) {
                    return false;
                }
            }

            // never the warning colour (the ink and the fill)
            if ($warning !== null && (self::deltaE($warning, $ink) < $minWarning || self::deltaE($warning, $fill) < $minWarning)) {
                return false;
            }

            return true;
        };

        if ($danger !== null && $dangerFill !== null) {
            $ink = self::over($danger, $bg);
            $fill = self::over($dangerFill, $s1);

            if ($passes($ink, $fill, self::BAR)) {
                return ['strategy' => 'theme', 'ink' => $ink, 'fill' => $fill, 'label' => [$onDanger[0], $onDanger[1], $onDanger[2]], 'vars' => [
                    '--mk-danger' => 'var(--avd-danger)', '--mk-danger-rgb' => 'var(--avd-danger-rgb)',
                    '--mk-danger-fill' => 'var(--avd-danger-fill)', '--mk-on-danger' => 'var(--avd-on-danger)',
                ]];
            }
        }

        $neutral = ['strategy' => 'neutral', 'ink' => $text, 'fill' => $text, 'label' => $bg, 'vars' => [
            '--mk-danger' => 'var(--avd-text)', '--mk-danger-rgb' => $mode === 'light' ? 'var(--avd-shade-rgb)' : 'var(--avd-glint-rgb)',
            '--mk-danger-fill' => 'var(--avd-text)', '--mk-on-danger' => 'var(--avd-bg)',
        ]];

        if ($passes($text, $text, self::MIN_DE)) {
            return $neutral;
        }

        [$h] = self::lch(self::lab($brandInk));
        $red = $h >= 335 || $h <= 42;
        $families = $red ? ['orange', 'red'] : ['red', 'orange'];

        // an amber or orange warning (hue 40-100) sends the search to the red family first, the
        // oranges only after every red was too close to the brand
        if ($warning !== null) {
            [$wh, $wc] = self::lch(self::lab($warning));

            if ($wc >= 20 && $wh >= 40 && $wh <= 100) {
                $families = ['red', 'orange'];
            }
        }

        $list = self::CANDIDATES[$mode === 'light' ? 'light' : 'dark'];

        foreach ([self::WARN_DE, self::WARN_BAR] as $minWarning) {
            foreach ($families as $family) {
                $found = self::firstCandidate($list[$family], $surfaces, $passes, $minWarning);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return $neutral;
    }

    /**
     * The first candidate of a family that reads on every page surface, keeps its distances (brand, button, warning)
     * and carries a readable label.
     *
     * @param  list<string>  $hexes
     * @param  list<array{0:int,1:int,2:int}>  $surfaces
     * @return array<string, mixed>|null
     */
    private static function firstCandidate(array $hexes, array $surfaces, \Closure $passes, float $minWarning): ?array
    {
        foreach ($hexes as $hex) {
            $c = self::colour($hex);

            if ($c === null) {
                continue;
            }

            $rgb = [$c[0], $c[1], $c[2]];
            $ok = true;

            foreach ($surfaces as $surface) {
                if (self::contrast($rgb, $surface) < 4.5) {
                    $ok = false;
                    break;
                }
            }

            if (! $ok || ! $passes($rgb, $rgb, self::BAR, $minWarning)) {
                continue;
            }

            $label = null;

            foreach ([[255, 255, 255], [11, 11, 15]] as $candidate) {
                if (self::contrast($candidate, $rgb) >= 4.5) {
                    $label = $candidate;
                    break;
                }
            }

            if ($label === null) {
                continue;
            }

            return ['strategy' => 'generated', 'ink' => $rgb, 'fill' => $rgb, 'label' => $label, 'vars' => [
                '--mk-danger' => $hex, '--mk-danger-rgb' => $rgb[0].','.$rgb[1].','.$rgb[2],
                '--mk-danger-fill' => $hex, '--mk-on-danger' => sprintf('#%02x%02x%02x', ...$label),
            ]];
        }

        return null;
    }

    // ------------------------------------------------------------------ colour helpers

    /** @return array{0:int,1:int,2:int,3:float}|null #rgb, #rrggbb, #rrggbbaa, rgb() / rgba() */
    public static function colour(string $value): ?array
    {
        $v = strtolower(trim($value));

        if (preg_match('/^#([0-9a-f]{3})$/', $v, $m)) {
            return [hexdec($m[1][0].$m[1][0]), hexdec($m[1][1].$m[1][1]), hexdec($m[1][2].$m[1][2]), 1.0];
        }

        if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $v, $m)) {
            return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2)), isset($m[2]) && $m[2] !== '' ? hexdec($m[2]) / 255 : 1.0];
        }

        if (preg_match('/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,\/]+([\d.]+%?))?\s*\)$/', $v, $m)) {
            $a = isset($m[4]) && $m[4] !== '' ? (str_ends_with($m[4], '%') ? (float) $m[4] / 100 : (float) $m[4]) : 1.0;

            return [(int) round((float) $m[1]), (int) round((float) $m[2]), (int) round((float) $m[3]), $a];
        }

        $all = self::colours($v);

        return $all === [] ? null : $all[0];
    }

    /** Every colour in a value (gradient stops included). @return list<array{0:int,1:int,2:int,3:float}> */
    public static function colours(string $value): array
    {
        preg_match_all('/#[0-9a-f]{3,8}\b|rgba?\([^)]*\)/i', $value, $m);
        $out = [];

        foreach ($m[0] as $one) {
            $c = self::colour($one);

            if ($c !== null) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /** A colour with alpha composited over an opaque one. @return array{0:int,1:int,2:int} */
    public static function over(array $fg, array $bg): array
    {
        $a = $fg[3] ?? 1.0;

        return [(int) round($fg[0] * $a + $bg[0] * (1 - $a)), (int) round($fg[1] * $a + $bg[1] * (1 - $a)), (int) round($fg[2] * $a + $bg[2] * (1 - $a))];
    }

    public static function luminance(array $c): float
    {
        $ch = fn (float $v): float => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $ch($c[0]) + 0.7152 * $ch($c[1]) + 0.0722 * $ch($c[2]);
    }

    public static function contrast(array $a, array $b): float
    {
        [$x, $y] = [self::luminance($a), self::luminance($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** sRGB (D65) to CIELAB. @return array{0:float,1:float,2:float} */
    public static function lab(array $c): array
    {
        $lin = function (float $v): float {
            $v /= 255;

            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = [$lin($c[0]), $lin($c[1]), $lin($c[2])];
        $x = (0.4124 * $r + 0.3576 * $g + 0.1805 * $b) / 0.95047;
        $y = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        $z = (0.0193 * $r + 0.1192 * $g + 0.9505 * $b) / 1.08883;
        $f = fn (float $t): float => $t > 216 / 24389 ? $t ** (1 / 3) : (24389 / 27 * $t + 16) / 116;
        [$fx, $fy, $fz] = [$f($x), $f($y), $f($z)];

        return [116 * $fy - 16, 500 * ($fx - $fy), 200 * ($fy - $fz)];
    }

    /** @return array{0:float,1:float,2:float} hue (degrees), chroma, lightness */
    public static function lch(array $lab): array
    {
        return [fmod(rad2deg(atan2($lab[2], $lab[1])) + 360, 360), hypot($lab[1], $lab[2]), $lab[0]];
    }

    /** CIEDE2000 between two sRGB colours. */
    public static function deltaE(array $c1, array $c2): float
    {
        [$L1, $a1, $b1] = self::lab($c1);
        [$L2, $a2, $b2] = self::lab($c2);
        $C1 = hypot($a1, $b1);
        $C2 = hypot($a2, $b2);
        $Cm = ($C1 + $C2) / 2;
        $G = 0.5 * (1 - sqrt($Cm ** 7 / ($Cm ** 7 + 25 ** 7)));
        [$a1p, $a2p] = [(1 + $G) * $a1, (1 + $G) * $a2];
        [$C1p, $C2p] = [hypot($a1p, $b1), hypot($a2p, $b2)];
        $h1p = fmod(rad2deg(atan2($b1, $a1p)) + 360, 360);
        $h2p = fmod(rad2deg(atan2($b2, $a2p)) + 360, 360);
        $dLp = $L2 - $L1;
        $dCp = $C2p - $C1p;

        if ($C1p * $C2p == 0) {
            $dhp = 0;
        } elseif (abs($h2p - $h1p) <= 180) {
            $dhp = $h2p - $h1p;
        } else {
            $dhp = $h2p > $h1p ? $h2p - $h1p - 360 : $h2p - $h1p + 360;
        }

        $dHp = 2 * sqrt($C1p * $C2p) * sin(deg2rad($dhp / 2));
        $Lpm = ($L1 + $L2) / 2;
        $Cpm = ($C1p + $C2p) / 2;

        if ($C1p * $C2p == 0) {
            $hpm = $h1p + $h2p;
        } elseif (abs($h1p - $h2p) <= 180) {
            $hpm = ($h1p + $h2p) / 2;
        } else {
            $hpm = $h1p + $h2p < 360 ? ($h1p + $h2p + 360) / 2 : ($h1p + $h2p - 360) / 2;
        }

        $T = 1 - 0.17 * cos(deg2rad($hpm - 30)) + 0.24 * cos(deg2rad(2 * $hpm)) + 0.32 * cos(deg2rad(3 * $hpm + 6)) - 0.20 * cos(deg2rad(4 * $hpm - 63));
        $dTheta = 30 * exp(-((($hpm - 275) / 25) ** 2));
        $Rc = 2 * sqrt($Cpm ** 7 / ($Cpm ** 7 + 25 ** 7));
        $Sl = 1 + (0.015 * ($Lpm - 50) ** 2) / sqrt(20 + ($Lpm - 50) ** 2);
        $Sc = 1 + 0.045 * $Cpm;
        $Sh = 1 + 0.015 * $Cpm * $T;
        $Rt = -sin(deg2rad(2 * $dTheta)) * $Rc;

        return sqrt(($dLp / $Sl) ** 2 + ($dCp / $Sc) ** 2 + ($dHp / $Sh) ** 2 + $Rt * ($dCp / $Sc) * ($dHp / $Sh));
    }
}
