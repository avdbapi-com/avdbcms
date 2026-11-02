<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- never indexed: the wizard, "Already Installed", "Installation Not Finished" (round 3 R3-038; the answers carry X-Robots-Tag too) --}}
    <meta name="robots" content="noindex, nofollow">
    <title>AVDBCMS Installer</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #080b12;
            --bg-soft: #0d121c;
            --panel: rgba(20, 25, 37, .92);
            --panel-2: rgba(27, 34, 49, .92);
            --ink: #f7f9ff;
            --muted: #aeb8cc;
            --muted-2: #7d879a;
            --line: rgba(255, 255, 255, .10);
            --line-strong: rgba(255, 255, 255, .18);
            --brand: #5d8cff;
            --brand-2: #77d6c3;
            --danger: #ff5f7a;
            --danger-2: #ff7b66;
            --warn: #ffca5c;
            --ok: #49c97b;
            --shadow: 0 24px 90px rgba(0, 0, 0, .38);
            --radius: 14px;
        }

        * { box-sizing: border-box; }

        html { min-height: 100%; background: var(--bg); }

        body {
            min-height: 100vh;
            margin: 0;
            color: var(--ink);
            background:
                radial-gradient(circle at 12% 0%, rgba(255, 95, 122, .20), transparent 28rem),
                radial-gradient(circle at 92% 8%, rgba(119, 214, 195, .16), transparent 24rem),
                linear-gradient(135deg, #090c13 0%, #111827 52%, #0a1119 100%);
            font: 15px/1.55 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            letter-spacing: 0;
        }

        a { color: #9db7ff; }
        /* a name such as INSTALLER_ALLOW_REINSTALL=true moves to the next line whole; only a path too long for any
           line breaks inside (R1-104: it broke mid-word wherever the line ended) */
        code { color: #d9e4ff; overflow-wrap: anywhere; word-break: normal; }
        pre { margin: 0; }

        .wrap {
            width: min(1220px, calc(100% - 28px));
            margin: 24px auto;
        }

        .shell {
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: rgba(10, 14, 22, .76);
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 24px;
            align-items: start;
            padding: 34px;
            background:
                linear-gradient(135deg, rgba(93, 140, 255, .20), transparent 45%),
                linear-gradient(100deg, rgba(255, 95, 122, .16), rgba(119, 214, 195, .10));
            border-bottom: 1px solid var(--line);
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
        }

        .logo-mark {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #ff5f7a, #ff9b68);
            box-shadow: 0 14px 40px rgba(255, 95, 122, .28);
            font-weight: 900;
            color: #0b0f18;
        }

        .eyebrow {
            margin: 0 0 3px;
            color: var(--brand-2);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        h1, h2, h3 { margin: 0; letter-spacing: 0; }
        h1 { font-size: clamp(30px, 5vw, 50px); line-height: 1.02; }
        h2 { font-size: clamp(23px, 3vw, 31px); line-height: 1.15; }
        h3 { font-size: 17px; }

        .hero-copy {
            max-width: 780px;
            margin: 12px 0 0;
            color: #dbe3f4;
            font-size: 16px;
        }

        .hero-aside {
            min-width: 286px;
            display: grid;
            gap: 10px;
        }

        .status-tile {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: rgba(8, 11, 18, .54);
        }

        .status-tile span:first-child { color: var(--muted); font-weight: 750; }
        .status-tile span:last-child { text-align: right; font-weight: 850; }

        .support-card {
            display: grid;
            gap: 10px;
            padding: 14px;
            border: 1px solid rgba(119, 214, 195, .34);
            border-radius: var(--radius);
            background: rgba(119, 214, 195, .08);
        }

        .support-card strong {
            color: #eafffa;
            font-size: 15px;
        }

        .support-links {
            display: grid;
            gap: 8px;
        }

        .support-links a {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 4px 12px;
            overflow-wrap: anywhere;
            padding: 9px 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: rgba(8, 11, 18, .42);
            color: #dce7ff;
            font-weight: 850;
            text-decoration: none;
        }

        .support-links span {
            color: var(--muted);
            font-weight: 750;
        }

        .stepper {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            background: rgba(8, 11, 18, .58);
            border-bottom: 1px solid var(--line);
        }

        .step {
            position: relative;
            min-height: 82px;
            display: grid;
            align-content: center;
            gap: 8px;
            padding: 18px 18px 18px 22px;
            color: var(--muted);
            text-decoration: none;
        }

        .step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 50%;
            right: 0;
            width: 1px;
            height: 38px;
            background: var(--line);
            transform: translateY(-50%);
        }

        .step-kicker {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .dot {
            flex: 0 0 auto;
            width: 30px;
            height: 30px;
            border-radius: 999px;
            display: grid;
            place-items: center;
            border: 1px solid var(--line-strong);
            background: #222a3a;
            color: #dfe6f6;
            font-size: 12px;
            font-weight: 900;
        }

        .step-label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 850;
        }

        .step small { color: var(--muted-2); font-weight: 750; }
        .step.done .dot { background: rgba(73, 201, 123, .20); border-color: rgba(73, 201, 123, .65); color: #95f2b9; }
        .step.active { color: var(--ink); background: linear-gradient(180deg, rgba(93, 140, 255, .18), rgba(93, 140, 255, .05)); }
        .step.active .dot { background: var(--brand); border-color: var(--brand); color: #fff; }

        .body { padding: 30px 34px 36px; }

        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1.18fr) minmax(320px, .82fr);
            gap: 22px;
            align-items: start;
        }

        .grid.equal { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        /* one panel alone (the locked page): centred, no empty half beside it */
        .grid.single { grid-template-columns: minmax(0, 760px); justify-content: center; }

        /* the help card at the end of the page: phones and tablets only (the hero has it on wide screens) */
        .bottom-help { display: none; }

        .panel {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--panel);
            box-shadow: 0 16px 52px rgba(0, 0, 0, .20);
        }

        .panel.pad { padding: 24px; }

        .panel-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            padding: 20px 22px;
            border-bottom: 1px solid var(--line);
            background: rgba(255, 255, 255, .02);
        }

        .panel-body { padding: 20px 22px 22px; }

        .muted { color: var(--muted); }
        .tight { margin: 8px 0 0; }
        .spacer { height: 16px; }

        .mini-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .metric {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 13px 14px;
            background: rgba(255, 255, 255, .035);
        }

        .metric strong {
            display: block;
            font-size: 24px;
            line-height: 1;
            margin-bottom: 7px;
        }

        .metric span { color: var(--muted); font-size: 13px; font-weight: 750; }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            border-radius: 999px;
            padding: 5px 10px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, .06);
            color: #dfe6f6;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .badge.ok { color: #aff3c8; border-color: rgba(73, 201, 123, .45); background: rgba(73, 201, 123, .12); }
        .badge.warn { color: #ffe39b; border-color: rgba(255, 202, 92, .44); background: rgba(255, 202, 92, .12); }
        .badge.bad { color: #ffc2ce; border-color: rgba(255, 95, 122, .48); background: rgba(255, 95, 122, .13); }
        .badge.info { color: #c9d8ff; border-color: rgba(93, 140, 255, .48); background: rgba(93, 140, 255, .13); }

        .notice {
            display: grid;
            gap: 4px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 14px 16px;
            margin-bottom: 18px;
            background: rgba(255, 255, 255, .045);
        }

        .notice.bad { border-color: rgba(255, 95, 122, .46); background: rgba(255, 95, 122, .10); }
        .notice.ok { border-color: rgba(73, 201, 123, .42); background: rgba(73, 201, 123, .10); }
        .notice.warn { border-color: rgba(255, 202, 92, .38); background: rgba(255, 202, 92, .09); }

        .check-list { display: grid; gap: 12px; }

        .check-card {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 14px;
            padding: 15px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(255, 255, 255, .035);
        }

        .check-card.danger { border-color: rgba(255, 95, 122, .40); background: rgba(255, 95, 122, .075); }
        .check-card.warning { border-color: rgba(255, 202, 92, .32); background: rgba(255, 202, 92, .065); }

        .check-title {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 9px;
            margin-bottom: 7px;
        }

        .check-title strong { font-size: 16px; }
        .check-detail { color: #dbe3f4; font-weight: 760; }
        .check-help { margin-top: 7px; color: var(--muted); }
        .check-fix {
            margin-top: 11px;
            padding: 11px 12px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, .10);
            background: rgba(8, 11, 18, .55);
            color: #e9efff;
        }

        .check-fix strong { color: #fff; }
        .check-meta { display: grid; justify-items: end; gap: 8px; align-content: start; }

        .summary {
            display: grid;
            grid-template-columns: minmax(120px, .42fr) minmax(0, 1fr);
            gap: 10px 16px;
            margin: 0;
        }

        .summary dt { color: var(--muted); font-weight: 850; }
        .summary dd { margin: 0; word-break: break-word; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            /* a field with a hint under it never stretches the field beside it */
            align-items: start;
        }

        .field { display: grid; gap: 7px; }
        label { color: #edf2ff; font-weight: 850; }

        input, select {
            width: 100%;
            min-height: 46px;
            border: 1px solid var(--line-strong);
            border-radius: 12px;
            padding: 11px 12px;
            color: var(--ink);
            background: rgba(255, 255, 255, .06);
            font: inherit;
        }

        select { cursor: pointer; }
        input::placeholder { color: #7f899b; }
        input:focus, select:focus { outline: 3px solid rgba(93, 140, 255, .22); border-color: rgba(93, 140, 255, .85); }

        .span-2 { grid-column: 1 / -1; }

        .option {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(255, 255, 255, .035);
        }

        .option + .option { margin-top: 12px; }
        .option input { width: auto; min-height: 0; margin-top: 4px; }

        .actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 22px;
        }

        .btn {
            min-height: 44px;
            appearance: none;
            border: 1px solid transparent;
            border-radius: 12px;
            padding: 11px 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--ink);
            font: inherit;
            font-weight: 900;
            text-decoration: none;
            cursor: pointer;
        }

        .btn.primary { background: linear-gradient(135deg, #5d8cff, #7967ff); color: #fff; box-shadow: 0 12px 34px rgba(93, 140, 255, .24); }
        .btn.secondary { border-color: var(--line); background: rgba(255, 255, 255, .07); color: #eef3ff; }
        .btn.danger { background: linear-gradient(135deg, #ff5f7a, #ff9267); color: #0b0f18; box-shadow: 0 12px 34px rgba(255, 95, 122, .22); }
        .btn.ghost { border-color: transparent; background: transparent; color: #b9c6de; }
        .btn:disabled { opacity: .52; cursor: not-allowed; }

        .config-wrap {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #050812;
        }

        .config-toolbar {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            padding: 10px 12px;
            border-bottom: 1px solid var(--line);
            color: var(--muted);
            font-size: 12px;
            font-weight: 850;
        }

        .config-snippet {
            max-height: 310px;
            overflow: auto;
            white-space: pre;
            padding: 14px;
            color: #e8edf7;
            font-size: 13px;
            line-height: 1.5;
        }

        .copy-btn {
            min-height: 30px;
            border-radius: 9px;
            padding: 5px 10px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, .07);
            color: #eef3ff;
            font: inherit;
            font-size: 12px;
            font-weight: 900;
            cursor: pointer;
        }

        .timeline { display: grid; gap: 12px; }

        .timeline-row {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 12px;
            align-items: start;
        }

        /* a long word in a result line (a path, a file name) breaks instead of running out of the panel */
        .timeline-row > div { overflow-wrap: anywhere; }

        .timeline-mark {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            display: grid;
            place-items: center;
            background: rgba(73, 201, 123, .12);
            border: 1px solid rgba(73, 201, 123, .42);
            color: #aff3c8;
            font-weight: 900;
            font-size: 12px;
        }

        .footer-note {
            margin-top: 18px;
            color: var(--muted-2);
            font-size: 13px;
        }

        /* The installation's progress page (R1B-001): the bar, the phases and the notes of the steps. */
        .run-bar {
            height: 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, .06);
            overflow: hidden;
        }

        .run-bar > span {
            display: block;
            height: 100%;
            width: 0;
            border-radius: inherit;
            background: linear-gradient(90deg, #5d8cff, #77d6c3);
            transition: width .4s ease;
        }

        .run-status { margin: 12px 0 0; color: #dbe3f4; font-weight: 760; }
        .phase-list { list-style: none; margin: 18px 0 0; padding: 0; display: grid; gap: 8px; }

        .phase {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 4px 12px;
            align-items: center;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(255, 255, 255, .03);
            color: var(--muted);
        }

        .phase .phase-mark {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            display: grid;
            place-items: center;
            border: 1px solid var(--line-strong);
            font-size: 12px;
            font-weight: 900;
        }

        .phase .phase-label { color: var(--ink); font-weight: 800; overflow-wrap: anywhere; }
        .phase .phase-detail { grid-column: 2; font-size: 13px; }
        .phase .phase-detail:empty { display: none; }
        .phase .phase-state { grid-column: 3; grid-row: 1; font-size: 12px; font-weight: 900; }
        .phase.done .phase-mark { background: rgba(73, 201, 123, .20); border-color: rgba(73, 201, 123, .65); color: #95f2b9; }
        .phase.current { border-color: rgba(93, 140, 255, .55); background: rgba(93, 140, 255, .10); }
        .phase.current .phase-mark { background: var(--brand); border-color: var(--brand); color: #fff; }
        .phase.failed { border-color: rgba(255, 95, 122, .50); background: rgba(255, 95, 122, .09); }
        .phase.failed .phase-mark { background: rgba(255, 95, 122, .25); border-color: rgba(255, 95, 122, .7); color: #ffc2ce; }
        .run-notes { margin: 14px 0 0; padding-inline-start: 20px; color: var(--muted); font-size: 14px; }
        .run-notes:empty { display: none; }
        .run-notes li + li { margin-top: 6px; }
        [hidden] { display: none !important; }

        @media (prefers-reduced-motion: reduce) {
            .run-bar > span { transition: none; }
        }

        /* Phones and tablets (R1-105): the form of each step starts in the first screen. After the first step the hero
           keeps only the brand (its text and the detected values are on the steps themselves), the six steps are one
           row of numbers with the current step's name (on phones on the line under them), and the help card moves to
           the end of the page. */
        @media (max-width: 980px) {
            /* minmax(0, 1fr), not 1fr: a plain 1fr column grows to its widest unbreakable content (the cron line, the
               server snippet), and the panels were cut at the right of the screen (round 3 R3-007). Long lines scroll
               inside their own box (.config-snippet). */
            .hero, .grid, .grid.equal, .grid.single { grid-template-columns: minmax(0, 1fr); }
            .hero-aside { min-width: 0; }
            .hero-compact { gap: 0; }
            .hero-compact .hero-copy, .hero-compact .hero-aside { display: none; }
            .hero-compact .brand-row { margin-bottom: 0; }
            .hero-help { display: none; }
            .bottom-help { display: grid; margin-top: 22px; }
            .stepper { display: flex; align-items: center; gap: 6px; padding: 10px 14px; }
            .step { min-height: 0; padding: 4px; flex: 0 0 auto; }
            .step.active { flex: 0 1 auto; min-width: 0; background: none; }
            .step small { display: none; }
            .step:not(:last-child)::after { display: none; }
            .step:not(.active) .step-label {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                clip-path: inset(50%);
                white-space: nowrap;
            }
            .mini-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 640px) {
            .wrap { width: min(100% - 18px, 1220px); margin: 9px auto; }
            .hero, .body { padding: 20px; }
            .hero-compact { padding-block: 16px; }
            .hero-compact h1 { font-size: 26px; }
            /* the first step's panel lists the same detected values (backup, document root, panel) */
            .hero-aside { display: none; }
            /* the current step's name on its own line under the six numbers: beside them it was cut to "Ad…" at 360 */
            .stepper { position: relative; padding-block-end: 36px; }
            .step { position: static; }
            .step.active .step-label { position: absolute; inset-inline: 14px; inset-block-end: 10px; }
            .panel-head, .panel-body, .panel.pad { padding: 17px; }
            .panel-head { flex-wrap: wrap; }
            .panel-head > div { flex: 1 1 14rem; min-width: 0; }
            .check-card, .form-grid, .summary { grid-template-columns: 1fr; }
            .check-meta { justify-items: start; }
            .span-2 { grid-column: auto; }
            .actions { justify-content: stretch; }
            .btn { width: 100%; }
        }
    </style>
</head>
@php
    $stepKeys = array_column($steps, 'key');
    $activeIndex = max(0, array_search(in_array($step, ['finished', 'installing'], true) ? 'install' : $step, $stepKeys, true));
    // Public pages (an installed site, an unfinished installation of somebody else) name no server detail and no steps.
    $publicPage = in_array($step, ['installed', 'resume'], true);
    $path = trim(request()->path(), '/');
    // The wizard's own addresses start with the folder of a site installed in one (https://example.com/site; R2R-05).
    $pfx = request()->getBasePath() . (!empty($_SERVER['AVDBCLIP_ROOT_FALLBACK'])
        || !empty($_SERVER['AVDBCLIP_INDEX_PHP_PREFIX'])
        || str_starts_with($path, 'index.php/install')
        ? '/index.php'
        : '');
    $install = fn (string $target = '') => $target === '' ? $pfx . '/install' : $pfx . '/install?'.http_build_query(['step' => $target]);
    $post = fn (string $target) => $pfx . '/install/'.rawurlencode($target);
    $oldOr = fn (string $key, mixed $default = '') => old($key, $default);
    $requirementRequiredFailed = collect($requirements)->where('required', true)->where('ok', false)->count();
    $requirementOptionalFailed = collect($requirements)->where('required', false)->where('ok', false)->count();
    $requirementReady = collect($requirements)->where('ok', true)->count();
    $filesystemRequiredFailed = collect($filesystem)->where('required', true)->where('ok', false)->count();
    $siteReady = $step === 'finished' || $step === 'installed';
@endphp
<body>
<div class="wrap">
    <div class="shell">
        <header class="hero{{ $step === 'introduction' ? '' : ' hero-compact' }}">
            <div>
                <div class="brand-row">
                    <div class="logo-mark">AV</div>
                    <div>
                        <p class="eyebrow">Community package setup</p>
                        <h1>AVDBCMS Installer</h1>
                    </div>
                </div>
                <p class="hero-copy">
                    Guided setup for shared hosting, VPS panels, Docker, local stacks, Apache, Nginx, LiteSpeed, Caddy, IIS, MySQL, and MariaDB.
                    The installer detects your environment, repairs safe local issues, imports the bundled database, creates the admin account, writes `.env`, and locks itself.
                </p>
            </div>

            <aside class="hero-aside" aria-label="Detected environment">
                <div class="status-tile"><span>Domain</span><span>{{ $detectedUrl }}</span></div>
                {{-- After installation this page is public: it names no server detail (web server, files, paths). --}}
                @if(! $publicPage)
                    <div class="status-tile"><span>Web server</span><span>{{ $environment['web_server'] }}</span></div>
                    <div class="status-tile"><span>SQL backup</span><span class="{{ $backup['exists'] ? '' : 'muted' }}">{{ $backup['exists'] ? $backup['filename'] : 'Not bundled' }}</span></div>
                @endif
                <div class="support-card hero-help">
                    <strong>Need help during install?</strong>
                    {{-- The AVDBAPI Telegram group and personal support (App\Support\TelegramLinks). --}}
                    <div class="support-links">
                        <a href="{{ \App\Support\TelegramLinks::GROUP_URL }}" target="_blank" rel="noopener">
                            <span>Telegram group</span>
                            {{ \App\Support\TelegramLinks::GROUP_TEXT }}
                        </a>
                        <a href="{{ \App\Support\TelegramLinks::SUPPORT_URL }}" target="_blank" rel="noopener">
                            <span>Telegram support</span>
                            {{ \App\Support\TelegramLinks::SUPPORT_HANDLE }}
                        </a>
                    </div>
                </div>
            </aside>
        </header>

        {{-- No steps on the locked page: nothing is being installed there (R1-104 showed "Introduction - Current step"). --}}
        @if(! $publicPage)
        <nav class="stepper" aria-label="Installation steps">
            @foreach($steps as $index => $item)
                @php($state = $index < $activeIndex || $step === 'finished' ? 'done' : ($index === $activeIndex ? 'active' : ''))
                @php($tag = $index < $activeIndex ? 'a' : 'div')
                <{{ $tag }}
                    @if($tag === 'a') href="{{ $install($item['key']) }}" @endif
                    class="step {{ $state }}"
                    @if($state === 'active') aria-current="step" @endif
                >
                    <span class="step-kicker">
                        <span class="dot">{{ $state === 'done' ? 'OK' : $index + 1 }}</span>
                        <span class="step-label">{{ $item['label'] }}</span>
                    </span>
                    <small>{{ $state === 'done' ? 'Completed' : ($state === 'active' ? 'Current step' : 'Pending') }}</small>
                </{{ $tag }}>
            @endforeach
        </nav>
        @endif

        <main class="body">
            @if($errors->any())
                <div class="notice bad" role="alert">
                    <strong>Action needed</strong>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            @if(is_array($pending ?? null) && ($pending['reinstall'] ?? false) && in_array($step, ['introduction', 'install'], true))
                {{-- INSTALLER_ALLOW_REINSTALL is set and an installation started from another browser did not finish (BI-RV-02):
                     its owner goes on with it (the password of its database), or Install Now starts again in its place once
                     no browser drives it any more (Installer::startRun()). No detail of it (database, user, host, path). --}}
                @php($idleMinutes = (int) ceil(($pending['idle_limit'] ?? 120) / 60))
                @php($stillRunning = ($pending['idle_seconds'] ?? PHP_INT_MAX) < ($pending['idle_limit'] ?? 120))
                @php($whenStopped = $stillRunning ? ' once it has stopped for '.$idleMinutes.' minutes - its last step was '.intval($pending['idle_seconds']).' seconds ago' : '')
                <div class="notice warn" data-other-run>
                    <strong>An installation started from another browser has not finished{{ ($pending['percent'] ?? null) !== null ? ' ('.(int) $pending['percent'].'%)' : '' }}</strong>
                    @if($step === 'introduction')
                        <span>
                            {{ ($pending['provable'] ?? false) ? 'To go on with it, enter the password of its database below.' : 'Its database user has no password, so it goes on only in the browser that started it.' }}
                            To start again instead, go through the steps: Install Now starts a new installation in its place{{ $whenStopped }}.
                        </span>
                    @else
                        <span>Install Now starts a new installation with the settings above in its place{{ $whenStopped }}.{{ ($pending['provable'] ?? false) ? ' To go on with it instead, enter the password of its database on the first page of the installer.' : '' }}</span>
                    @endif
                </div>
                @if($step === 'introduction' && ($pending['provable'] ?? false))
                    @include('install.resume-file')
                    <form method="POST" action="{{ $post('resume') }}" class="panel pad" style="margin-bottom: 18px;">
                        @csrf
                        <div class="field">
                            <label for="resume_password">Database password of that installation</label>
                            <input id="resume_password" type="password" name="resume_password" autocomplete="off" required aria-describedby="resume_hint">
                            <small id="resume_hint" class="muted">The password that was entered in the Database step of that installation.</small>
                        </div>
                        <div class="actions">
                            <button class="btn primary" type="submit">Continue That Installation</button>
                        </div>
                    </form>
                @endif
            @endif

            @if($step === 'installed')
                <section class="grid single">
                    <div class="panel pad">
                        <span class="badge ok">Locked</span>
                        <div class="spacer"></div>
                        <h2>Already Installed</h2>
                        <p class="muted tight">
                            The installer is locked because this application already has an admin account or an installer lock file.
                            This protects public installs from being overwritten.
                        </p>
                        @if($reinstallPending ?? false)
                            {{-- A reinstallation on another database did not finish; this site keeps working (BI-RV-02). --}}
                            <div class="notice warn" style="margin-top: 18px;">
                                <strong>A reinstallation did not finish</strong>
                                <span>It was started with <code>INSTALLER_<wbr>ALLOW_<wbr>REINSTALL=<wbr>true</code>. To go on with it or start it again, set that line in this site's <code>.env</code> file again and reload this page. To drop it, delete <code>storage/<wbr>framework/<wbr>avd-install-run.json</code>.</span>
                            </div>
                        @endif
                        <div class="notice warn" style="margin-top: 18px;">
                            <strong>Need to reinstall?</strong>
                            {{-- <wbr>: where a narrow phone must wrap these names, they wrap at their joints, never inside a word --}}
                            <span>Remove <code>storage/<wbr>app/<wbr>installed.json</code> and set <code>INSTALLER_<wbr>ALLOW_<wbr>REINSTALL=<wbr>true</code> only when you intentionally want to run setup again.</span>
                        </div>
                        <div class="actions">
                            <a href="{{ url('/') }}" class="btn secondary">Open Site</a>
                            <a href="{{ url('/admin') }}" class="btn primary">Open Admin</a>
                        </div>
                    </div>
                </section>
            @elseif($step === 'resume')
                {{-- An installation that did not finish, seen by anybody but its owner (R1B-001): the owner continues it with
                     the password of its database; nothing here names the database, its host, its user or a path of the server. --}}
                <section class="grid single">
                    <div class="panel pad">
                        <span class="badge warn">Not finished</span>
                        <div class="spacer"></div>
                        <h2>Installation Not Finished</h2>
                        <p class="muted tight">
                            @if(($pending['kind'] ?? '') === 'run')
                                An installation of this site was started{{ ! empty($pending['started_at']) ? ' on '.gmdate('Y-m-d \a\t H:i', (int) $pending['started_at']).' (UTC)' : '' }} and has not finished yet. Only its owner can continue it.
                            @else
                                The installation of this site did not finish: the site has no admin account and no installer lock yet. Only its owner can continue it.
                            @endif
                        </p>
                        @if($pending['provable'] ?? false)
                            @include('install.resume-file')
                            <form method="POST" action="{{ $post('resume') }}">
                                @csrf
                                <div class="field" style="margin-top: 18px;">
                                    <label for="resume_password">Database password of this installation</label>
                                    <input id="resume_password" type="password" name="resume_password" autocomplete="off" required aria-describedby="resume_hint">
                                    <small id="resume_hint" class="muted">
                                        {{ ($pending['kind'] ?? '') === 'run' ? 'The password that was entered in the Database step of this installation.' : 'The password of the database user, as written in DB_PASSWORD of this site\'s .env file.' }}
                                    </small>
                                </div>
                                <div class="actions">
                                    <button class="btn primary" type="submit">Continue the Installation</button>
                                </div>
                            </form>
                        @elseif(($pending['kind'] ?? '') === 'run')
                            {{-- An empty database password proves nothing (BI-RV-02): no form that can never succeed. --}}
                            <div class="notice warn" style="margin-top: 18px;">
                                <strong>It cannot be continued from here</strong>
                                <span>The database user of this installation has no password, so nobody can prove it is theirs: it goes on only in the browser that started it. Otherwise start it again (below).</span>
                            </div>
                        @endif
                        <div class="notice warn" style="margin-top: 18px;">
                            <strong>Start again instead?</strong>
                            {{-- <wbr>: names wrap at their joints on a narrow phone (R1-104) --}}
                            @if(($pending['kind'] ?? '') === 'run')
                                <span>Set <code>INSTALLER_<wbr>ALLOW_<wbr>REINSTALL=<wbr>true</code> in this site's <code>.env</code> file and reload this page: the installer opens, and its Install Now starts the installation again once this one has stopped for {{ (int) ceil(($pending['idle_limit'] ?? 120) / 60) }} minutes. Or delete <code>storage/<wbr>framework/<wbr>avd-install-run.json</code> and reload this page.</span>
                            @else
                                <span>Set <code>INSTALLER_<wbr>ALLOW_<wbr>REINSTALL=<wbr>true</code> in this site's <code>.env</code> file, then reload this page.</span>
                            @endif
                        </div>
                    </div>
                </section>
            @elseif($step === 'installing')
                {{-- inline statements only here: a block form after the stepper's inline ones would swallow them (Blade pairs them) --}}
                @php($progress = is_array($run ?? null) ? $run : ['status' => 'missing', 'percent' => 0, 'phases' => [], 'messages' => [], 'notes' => [], 'error' => null])
                @php($runStatus = (string) ($progress['status'] ?? 'working'))
                @php($currentPhase = collect($progress['phases'] ?? [])->first(fn ($phase) => in_array($phase['state'], ['current', 'failed'], true)))
                @php($phaseStates = ['done' => 'Done', 'current' => 'Working', 'waiting' => 'Waiting', 'failed' => 'Stopped'])
                <section class="grid" data-install-run data-run-url="{{ $post('run') }}" data-status-url="{{ $install('installing') }}" data-install-url="{{ $install() }}" data-install-step-url="{{ $install('install') }}" data-login-url="{{ url('/login') }}" data-admin-url="{{ url('/admin') }}" data-status="{{ $runStatus }}" data-csrf="{{ csrf_token() }}">
                    <div class="panel">
                        <div class="panel-head">
                            <div>
                                <h2>Installing</h2>
                                <p class="muted tight">The installer works in short steps, so that no request runs long enough for the server to stop it. Keep this page open until the result appears.</p>
                            </div>
                            <span class="badge info" data-run-badge>{{ (int) $progress['percent'] }}%</span>
                        </div>
                        <div class="panel-body">
                            <div class="run-bar" role="progressbar" aria-label="Installation progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $progress['percent'] }}" data-run-bar>
                                <span style="width: {{ (int) $progress['percent'] }}%"></span>
                            </div>
                            <p class="run-status" role="status" aria-live="polite" data-run-status>
                                @if($runStatus === 'failed')
                                    The installation stopped. Read the message below, then try again.
                                @elseif($runStatus === 'changed')
                                    Nothing was done: the database settings of this browser changed. Read the message below.
                                @elseif($runStatus === 'ready')
                                    The database and the admin account are ready. Click Finish Installation to write .env and lock the installer.
                                @elseif($runStatus === 'busy')
                                    Another step of this installation is running (another tab?). Wait a moment.
                                @else
                                    {{ $currentPhase ? 'Next: '.$currentPhase['label'].($currentPhase['detail'] !== '' ? ' ('.$currentPhase['detail'].')' : '') : 'Ready for the next step.' }}
                                @endif
                            </p>
                            <ol class="phase-list" data-run-phases>
                                @foreach($progress['phases'] as $phase)
                                    <li class="phase {{ $phase['state'] }}">
                                        <span class="phase-mark" aria-hidden="true">{{ $phase['state'] === 'done' ? 'OK' : ($phase['state'] === 'failed' ? '!' : $loop->iteration) }}</span>
                                        <span class="phase-label">{{ $phase['label'] }}</span>
                                        <span class="phase-state">{{ $phaseStates[$phase['state']] ?? '' }}</span>
                                        <span class="phase-detail">{{ $phase['detail'] }}</span>
                                    </li>
                                @endforeach
                            </ol>
                            <div class="notice bad" role="alert" style="margin: 16px 0 0;" data-run-error @if(! in_array($runStatus, ['failed', 'changed'], true)) hidden @endif>
                                <strong data-run-error-title>{{ $runStatus === 'changed' ? 'The database settings changed' : 'The installation stopped' }}</strong>
                                <span data-run-error-text>{{ $progress['error'] ?? '' }}</span>
                            </div>
                            <ul class="run-notes" data-run-notes>@foreach($progress['notes'] ?? [] as $note)<li>{{ $note }}</li>@endforeach</ul>
                            <form method="POST" action="{{ $post('run') }}" data-run-form>
                                @csrf
                                <input type="hidden" name="retry" value="{{ $runStatus === 'failed' ? '1' : '0' }}" data-run-retry>
                                <div class="actions">
                                    {{-- A run whose settings changed goes back to the Install step (Install Now starts again; BI-RV-01). --}}
                                    <a href="{{ $runStatus === 'changed' ? $install('install') : $install('database') }}" class="btn secondary" data-run-back @if(! in_array($runStatus, ['failed', 'changed'], true)) hidden @endif>{{ $runStatus === 'changed' ? 'Back to Install' : 'Back to Database' }}</a>
                                    <button class="btn primary" type="submit" data-run-continue @if($runStatus === 'changed') hidden @endif>{{ $runStatus === 'ready' ? 'Finish Installation' : ($runStatus === 'failed' ? 'Try Again' : 'Continue Installation') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <aside class="panel pad">
                        <h3>How This Works</h3>
                        <p class="muted">Each step does a few seconds of work, then the page starts the next one. If the server stops a step early (a host's time limit for one request), the next step notices it and goes on from a safe point, in shorter steps.</p>
                        <p class="muted">The site's <code>.env</code> is written last, after the database and the admin account are ready: until then an unfinished installation never looks installed, and only you can continue it.</p>
                        <noscript><p class="footer-note">Without JavaScript, click Continue Installation after each step until the result appears.</p></noscript>
                    </aside>
                </section>
            @elseif($step === 'finished')
                <div class="notice ok" data-install-finished>
                    <strong>Installation complete</strong>
                    <span>The app is configured, the admin account is ready, and the installer has locked itself.</span>
                </div>

                <section class="grid">
                    <div class="panel">
                        <div class="panel-head">
                            <div>
                                <h2>Setup Result</h2>
                                <p class="muted tight">Every operation that finished during the install is listed here.</p>
                            </div>
                            <span class="badge ok">Done</span>
                        </div>
                        <div class="panel-body timeline">
                            @foreach($messages as $message)
                                <div class="timeline-row">
                                    <span class="timeline-mark">OK</span>
                                    <div>{{ $message }}</div>
                                </div>
                            @endforeach
                            @foreach($notes ?? [] as $note)
                                <div class="timeline-row">
                                    <span class="timeline-mark" style="background: rgba(255, 202, 92, .12); border-color: rgba(255, 202, 92, .44); color: #ffe39b;">!</span>
                                    <div>{{ $note }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <aside class="panel pad">
                        <h3>Recommended After Install</h3>
                        {{-- What the last step did about the scheduler's cron line (Installer::schedulerAdvice(), round 3 R3-036): a
                             line to add only when the installer added none, and then exactly its own line (with the lock). --}}
                        @php($cron = is_array($scheduler ?? null) ? $scheduler : null)
                        @if($cron !== null && ! empty($cron['running']))
                            <p class="muted" data-scheduler="running">
                                The scheduled tasks (imports, queues, duration backfill, cleanup and email) start every minute from the cron line {{ match ($cron['state'] ?? '') { 'current' => 'that the crontab of the account PHP runs as already had', 'updated' => 'the installer put in the crontab of the account PHP runs as, in place of an older one', default => 'the installer added to the crontab of the account PHP runs as' } }}. Nothing to add: a second cron line for this site in your hosting panel would run them twice every minute.
                            </p>
                            <p class="footer-note">Tools &gt; Background jobs &gt; Scheduled tasks in the admin shows whether they run.</p>
                        @elseif($cron !== null)
                            {{-- A hosting panel's cron job takes the command alone: its schedule has fields of its own, and the
                                 "* * * * *" of a crontab line, run by the panel's shell, never started the tasks (round 3 IN-RV-01).
                                 The whole crontab line only where the account's crontab has the owner's own line of this site. --}}
                            @php($panelJob = empty($cron['windows']) && ! in_array($cron['state'] ?? '', ['off', 'custom', 'windows'], true) && ! empty($cron['command']))
                            @php($shown = $panelJob ? $cron['command'] : ($cron['line'] ?? null))
                            <p class="muted" data-scheduler="add">
                                @switch($cron['state'] ?? '')
                                    @case('off')
                                        This site's cron line in the crontab of the account PHP runs as is switched off (it starts with #), so the installer left it as it is. To run the scheduled tasks (imports, queues, duration backfill, cleanup and email) every minute, switch it on or put this line in its place.
                                        @break
                                    @case('custom')
                                        This site's cron line in the crontab of the account PHP runs as was changed by hand, so the installer left it as it is. If the scheduled tasks (imports, queues, duration backfill, cleanup and email) do not run, put this line in its place.
                                        @break
                                    @case('windows')
                                        On Windows the installer cannot add the scheduler by itself. Create the site's task in the Windows Task Scheduler once, with this command in a Command Prompt: it starts the scheduled tasks (imports, queues, duration backfill, cleanup and email) every minute.
                                        @break
                                    @default
                                        The installer could not add the scheduler's cron line.{{ ($cron['reason'] ?? '') !== '' ? ' '.$cron['reason'].'.' : '' }} {{ ! empty($cron['windows']) ? 'Create the site\'s task in the Windows Task Scheduler once, with this command in a Command Prompt, so the scheduled tasks (imports, queues, duration backfill, cleanup and email) start every minute.' : ($panelJob ? 'Add one cron job in your hosting panel that runs this command every minute as the site\'s user (the account PHP runs as), so the scheduled tasks (imports, queues, duration backfill, cleanup and email) keep moving.' : 'Add this line to the crontab of the site\'s user (the account PHP runs as), so the scheduled tasks (imports, queues, duration backfill, cleanup and email) keep moving.') }}
                                @endswitch
                            </p>
                            @if(! empty($shown))
                                <div class="config-wrap">
                                    <div class="config-toolbar">
                                        <span>{{ ! empty($cron['windows']) ? 'Command Prompt, once' : ($panelJob ? 'Cron job command, every minute' : 'Cron line, every minute') }}</span>
                                        <button class="copy-btn" type="button" data-copy="cron-command">Copy</button>
                                    </div>
                                    <pre id="cron-command" class="config-snippet">{{ $shown }}</pre>
                                </div>
                                @if(! empty($cron['panel_line']))
                                    <p class="footer-note">@if($panelJob)In the account's crontab (crontab -e) the line is <code style="white-space: nowrap">* * * * *</code> and then the command above. @endif If your panel runs cron jobs as root, use this command instead:</p>
                                    <div class="config-wrap">
                                        <div class="config-toolbar">
                                            <span>Cron job run as root</span>
                                            <button class="copy-btn" type="button" data-copy="cron-command-root">Copy</button>
                                        </div>
                                        <pre id="cron-command-root" class="config-snippet">{{ $cron['panel_line'] }}</pre>
                                    </div>
                                @endif
                                <p class="footer-note">{{ ! empty($cron['windows']) ? '' : 'Use the same PHP 8.3+ command line as the website. ' }}Tools &gt; Background jobs &gt; Scheduled tasks in the admin shows whether the tasks run.</p>
                            @else
                                <p class="footer-note">Tools &gt; Background jobs &gt; Scheduled tasks in the admin shows the line to add.</p>
                            @endif
                        @else
                            <p class="muted" data-scheduler="unknown">Tools &gt; Background jobs &gt; Scheduled tasks in the admin shows how the scheduled tasks (imports, queues, duration backfill, cleanup and email) start on this server, and the cron line to add when they do not run.</p>
                        @endif
                        <div class="actions">
                            <a href="{{ url('/login') }}" class="btn secondary">Login</a>
                            <a href="{{ url('/admin') }}" class="btn primary">Open Admin</a>
                        </div>
                    </aside>
                </section>
            @elseif($step === 'introduction')
                <section class="grid">
                    <div class="panel">
                        <div class="panel-head">
                            <div>
                                <h2>Start With a Real Environment Scan</h2>
                                <p class="muted tight">The next step checks the PHP version, extensions, Composer dependencies, frontend build, database driver, rewrite support, process functions, FFmpeg, and upload limits.</p>
                            </div>
                            <span class="badge info">Step 1</span>
                        </div>
                        <div class="panel-body">
                            <div class="mini-grid">
                                <div class="metric"><strong>{{ $backup['exists'] ? '1' : '0' }}</strong><span>SQL backups found</span></div>
                                <div class="metric"><strong>{{ $environment['public_document_root'] ? 'Yes' : 'Check' }}</strong><span>Public document root</span></div>
                                <div class="metric"><strong>{{ $environment['panel'] }}</strong><span>Detected panel</span></div>
                            </div>
                            <div class="notice warn" style="margin-top: 18px;">
                                <strong>For newbie installs</strong>
                                <span>If something fails, the installer shows the exact fix, the likely panel location, and whether it can be repaired automatically from PHP.</span>
                            </div>
                            <form method="POST" action="{{ $post('introduction') }}">
                                @csrf
                                <div class="actions">
                                    <button class="btn primary" type="submit">Start Installation</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <aside class="panel pad">
                        <h3>Database Setup</h3>
                        <div class="spacer"></div>
                        <dl class="summary">
                            <dt>Backup</dt><dd>{{ $backup['filename'] }}</dd>
                            <dt>Status</dt><dd><span class="badge {{ $backup['exists'] ? 'ok' : 'info' }}">{{ $backup['exists'] ? 'Found' : 'Fresh installation' }}</span></dd>
                            <dt>Size</dt><dd>{{ $backup['size'] }}</dd>
                            <dt>Source</dt><dd>{{ $backup['source'] }}</dd>
                            <dt>Document root</dt><dd>{{ $environment['public_document_root'] ? 'public/' : $environment['document_root'] }}</dd>
                        </dl>
                        <p class="footer-note">The installer uses <code>INSTALL_SQL_BACKUP</code> when set. Otherwise it auto-detects <code>.sql</code> files in <code>database/install</code> and <code>storage/app/backups</code>.</p>
                    </aside>
                </section>
            @elseif($step === 'requirements')
                <section class="grid">
                    <div class="panel">
                        <div class="panel-head">
                            <div>
                                <h2>Server Requirements</h2>
                                <p class="muted tight">Required items must pass. Recommended items improve imports, queue processing, local media tools, and upload reliability.</p>
                            </div>
                            <span class="badge {{ $requirementRequiredFailed === 0 ? 'ok' : 'bad' }}">
                                {{ $requirementRequiredFailed === 0 ? 'Ready' : $requirementRequiredFailed.' required fixes' }}
                            </span>
                        </div>
                        <div class="panel-body">
                            <div class="mini-grid" style="margin-top: 0;">
                                <div class="metric"><strong>{{ $requirementReady }}</strong><span>Checks ready</span></div>
                                <div class="metric"><strong>{{ $requirementRequiredFailed }}</strong><span>Required fixes</span></div>
                                <div class="metric"><strong>{{ $requirementOptionalFailed }}</strong><span>Recommended fixes</span></div>
                            </div>

                            <div class="notice info" style="margin-top: 18px;">
                                <strong>Safe auto repair</strong>
                                <span>The continue button creates missing runtime folders and writes a managed <code>.user.ini</code> recommendation when the project folder is writable. PHP version changes and missing extensions must be fixed in the hosting panel.</span>
                            </div>

                            @if(request()->getBasePath() !== '')
                                {{-- R2R-05: a site in a folder of its domain works there; what belongs to the domain is said here and in
                                     COMMUNITY_SETUP_GUIDE.md. --}}
                                <div class="notice info" style="margin-top: 12px;" data-site-folder="{{ request()->getBasePath() }}">
                                    <strong>A site in a folder of its domain ({{ request()->getBasePath() }})</strong>
                                    <span>The site's address will be <code>{{ $detectedUrl }}</code>: every page stays in that folder. Search engines read <code>robots.txt</code> only at the domain's root: add <code>Sitemap: {{ $detectedUrl }}{{ str_ends_with($pfx, '/index.php') ? '/index.php' : '' }}/sitemap.xml</code> to the domain's own <code>robots.txt</code>. On nginx, serve the folder with <code>docs/server-configs/nginx-subfolder.conf</code> (<code>nginx-project-root.conf</code> is for a domain's root only). More in COMMUNITY_SETUP_GUIDE.md, "A site in a folder of its domain".</span>
                                </div>
                            @endif

                            <div class="check-list">
                                @foreach($requirements as $check)
                                    <article class="check-card {{ $check['severity'] === 'danger' ? 'danger' : ($check['severity'] === 'warning' ? 'warning' : '') }}">
                                        <div>
                                            <div class="check-title">
                                                <strong>{{ $check['label'] }}</strong>
                                                <span class="badge {{ $check['ok'] ? 'ok' : ($check['required'] ? 'bad' : 'warn') }}">
                                                    {{ $check['ok'] ? 'Ready' : ($check['required'] ? 'Required' : 'Recommended') }}
                                                </span>
                                                @if($check['auto_fix'])
                                                    <span class="badge info">Auto repair</span>
                                                @endif
                                            </div>
                                            <div class="check-detail">{{ $check['detail'] }}</div>
                                            <div class="check-help">{{ $check['help'] }}</div>
                                            @if(!$check['ok'])
                                                <div class="check-fix">
                                                    <strong>{{ $check['action'] }}:</strong> {{ $check['fix'] }}
                                                    @if($check['docs'] !== '')
                                                        <br><span class="muted">{{ $check['docs'] }}</span>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                        <div class="check-meta">
                                            <span class="badge {{ $check['required'] ? 'info' : 'warn' }}">{{ $check['required'] ? 'Required' : 'Optional' }}</span>
                                        </div>
                                    </article>
                                @endforeach
                            </div>

                            <form method="POST" action="{{ $post('requirements') }}">
                                @csrf
                                <div class="actions">
                                    <a href="{{ $install('introduction') }}" class="btn secondary">Back</a>
                                    <a href="{{ $install('requirements') }}" class="btn ghost">Refresh Checks</a>
                                    <button class="btn primary" type="submit">Auto Repair and Continue</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <aside class="panel">
                        <div class="panel-head">
                            <div>
                                <h3>Web Server Setup</h3>
                                <p class="muted tight">Use this when routes or assets fail after install.</p>
                            </div>
                            <span class="badge info">{{ $environment['web_server'] }}</span>
                        </div>
                        <div class="panel-body">
                            <div class="check-list">
                                @foreach($serverGuidance as $item)
                                    <article class="check-card {{ $item['ok'] ? '' : 'warning' }}">
                                        <div>
                                            <div class="check-title">
                                                <strong>{{ $item['label'] }}</strong>
                                                <span class="badge {{ $item['ok'] ? 'ok' : 'warn' }}">{{ $item['ok'] ? 'OK' : 'Check' }}</span>
                                            </div>
                                            <div class="check-detail">{{ $item['detail'] }}</div>
                                            <div class="check-help">{{ $item['help'] }}</div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>

                            <div class="spacer"></div>
                            <div class="config-wrap">
                                <div class="config-toolbar">
                                    <span>Suggested server snippet</span>
                                    <button class="copy-btn" type="button" data-copy="server-config">Copy</button>
                                </div>
                                <pre id="server-config" class="config-snippet">{{ $serverConfigSnippet }}</pre>
                            </div>
                            <p class="footer-note">Nginx, Caddy, and IIS do not read <code>.htaccess</code>. If your panel cannot change the document root, use the bundled files in <code>docs/server-configs</code>.</p>
                        </div>
                    </aside>
                </section>
            @elseif($step === 'filesystem')
                <section class="grid">
                    <div class="panel">
                        <div class="panel-head">
                            <div>
                                <h2>Filesystem</h2>
                                <p class="muted tight">These folders must be writable by the PHP user. The installer tries to create missing runtime folders before continuing.</p>
                            </div>
                            <span class="badge {{ $filesystemRequiredFailed === 0 ? 'ok' : 'bad' }}">
                                {{ $filesystemRequiredFailed === 0 ? 'Writable' : $filesystemRequiredFailed.' blocked' }}
                            </span>
                        </div>
                        <div class="panel-body">
                            <div class="check-list">
                                @foreach($filesystem as $check)
                                    <article class="check-card {{ $check['ok'] ? '' : ($check['required'] ? 'danger' : 'warning') }}">
                                        <div>
                                            <div class="check-title">
                                                <strong>{{ $check['label'] }}</strong>
                                                <span class="badge {{ $check['ok'] ? 'ok' : ($check['required'] ? 'bad' : 'warn') }}">{{ $check['detail'] }}</span>
                                                @if($check['auto_fix'])
                                                    <span class="badge info">Auto repair</span>
                                                @endif
                                            </div>
                                            <code>{{ $check['path'] }}</code>
                                            <div class="check-help">{{ $check['help'] }}</div>
                                            @if(!$check['ok'])
                                                <div class="check-fix"><strong>Fix:</strong> {{ $check['fix'] }}</div>
                                            @endif
                                        </div>
                                        <div class="check-meta">
                                            <span class="badge {{ $check['required'] ? 'info' : 'warn' }}">{{ $check['required'] ? 'Required' : 'Optional' }}</span>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                            <form method="POST" action="{{ $post('filesystem') }}">
                                @csrf
                                <div class="actions">
                                    <a href="{{ $install('requirements') }}" class="btn secondary">Back</a>
                                    <button class="btn primary" type="submit">Repair and Continue</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <aside class="panel pad">
                        <h3>Permission Guide</h3>
                        <p class="muted">PHP must be able to write the project folder (the site's <code>.env</code> file), <code>storage</code>, <code>bootstrap/cache</code> and <code>public</code>. The simplest setup: every file of the site belongs to the user PHP runs as. Avoid 777 permissions.</p>
                        @if(($ownership['php_uid'] ?? null) !== null && ($ownership['owner_uid'] ?? null) !== null)
                            @php($sameOwner = $ownership['php_uid'] === $ownership['owner_uid'])
                            {{-- BI-RV-03: .env can belong to somebody else than its folder (made by root after the folder was given to PHP) --}}
                            @php($envForeign = is_array($envOwnership ?? null) && ($envOwnership['owner_uid'] ?? null) !== null && $envOwnership['owner_uid'] !== $ownership['php_uid'])
                            <div class="notice {{ $sameOwner && ! $envForeign ? 'ok' : 'warn' }}" style="margin: 16px 0 0;">
                                <strong>PHP runs as {{ $ownership['php_user'] ?? 'user id '.$ownership['php_uid'] }} (user id {{ $ownership['php_uid'] }})</strong>
                                @if($sameOwner)
                                    <span>The project folder belongs to the same user{{ $envForeign ? '.' : ': PHP can be given write access to everything it needs.' }}</span>
                                @else
                                    <span>The project folder belongs to {{ $ownership['owner'] ?? 'another user' }} (user id {{ $ownership['owner_uid'] }}). Write permission for that owner does not help PHP: give the site's folder to the PHP user, for example <code>chown -R {{ $ownership['php_uid'] }}:{{ $ownership['php_gid'] ?? $ownership['php_uid'] }} {{ $environment['project_root'] }}</code>, or set that owner in your panel's file manager.</span>
                                @endif
                                @if($envForeign && $sameOwner)
                                    <span>But its <code>.env</code> file belongs to {{ $envOwnership['owner'] ?? 'another user' }} (user id {{ $envOwnership['owner_uid'] }}): give it to the PHP user too, for example <code>chown {{ $ownership['php_uid'] }}:{{ $ownership['php_gid'] ?? $ownership['php_uid'] }} {{ rtrim($environment['project_root'], '/') }}/.env</code>.</span>
                                @endif
                            </div>
                        @endif
                        <div class="spacer"></div>
                        <div class="check-list">
                            <div class="check-card">
                                <div>
                                    <div class="check-title"><strong>Linux panels</strong><span class="badge info">Typical</span></div>
                                    <div class="check-help">In the file manager, set the owner of the site's folder (with its subfolders) to the user PHP runs as. In Docker and 1Panel, PHP usually runs as www-data: user id 82 in the Alpine PHP images, 1000 in 1Panel's PHP runtimes.</div>
                                </div>
                            </div>
                            <div class="check-card">
                                <div>
                                    <div class="check-title"><strong>Windows/IIS/OSPanel</strong><span class="badge info">Typical</span></div>
                                    <div class="check-help">Allow Modify permission for the IIS/PHP or local web-server user on runtime directories.</div>
                                </div>
                            </div>
                        </div>
                    </aside>
                </section>
            @elseif($step === 'database')
                <form method="POST" action="{{ $post('database') }}" class="grid">
                    @csrf
                    <div class="panel">
                        <div class="panel-head">
                            <div>
                                <h2>Database</h2>
                                <p class="muted tight">Create an empty MySQL or MariaDB database in your hosting panel, then enter the credentials here.</p>
                            </div>
                            <span class="badge info">MySQL/MariaDB</span>
                        </div>
                        <div class="panel-body form-grid">
                            <div class="field">
                                <label for="host">Host</label>
                                <input id="host" name="host" value="{{ $oldOr('host', $database['host'] ?? '127.0.0.1') }}" placeholder="127.0.0.1 or localhost" required>
                            </div>
                            <div class="field">
                                <label for="port">Port</label>
                                <input id="port" name="port" value="{{ $oldOr('port', $database['port'] ?? '3306') }}" required>
                            </div>
                            <div class="field">
                                <label for="database">Database name</label>
                                <input id="database" name="database" value="{{ $oldOr('database', $database['database'] ?? 'avdbcms') }}" required>
                            </div>
                            <div class="field">
                                <label for="username">Database user</label>
                                <input id="username" name="username" value="{{ $oldOr('username', $database['username'] ?? '') }}" required>
                            </div>
                            <div class="field span-2">
                                <label for="password">Database password</label>
                                <input id="password" type="password" name="password" value="{{ $database['password'] ?? '' }}">
                            </div>
                        </div>
                    </div>

                    <aside class="panel">
                        <div class="panel-head">
                            <div>
                                <h3>Import Mode</h3>
                                @if($backup['exists'])
                                    <p class="muted tight">Keep Import SQL backup for a new site: it loads the starting settings, languages, categories and plans. Run migrations and seeders builds empty tables with only the basic categories and plans.</p>
                                @else
                                    <p class="muted tight">No SQL backup was found in database/install or storage/app/backups, so this install runs the migrations and seeders.</p>
                                @endif
                            </div>
                            <span class="badge {{ $backup['exists'] ? 'ok' : 'info' }}">{{ $backup['exists'] ? 'SQL ready' : 'Fresh install' }}</span>
                        </div>
                        <div class="panel-body">
                            <label class="option">
                                <input type="radio" name="mode" value="backup" @checked($backup['exists'] && $oldOr('mode', $database['mode'] ?? ($backup['exists'] ? 'backup' : 'migrate')) === 'backup') @disabled(! $backup['exists'])>
                                <span><strong>Import SQL backup</strong><br><span class="muted">{{ $backup['exists'] ? $backup['filename'].' - '.$backup['size'].' ready' : 'No backup found in database/install or storage/app/backups' }}</span></span>
                            </label>
                            <label class="option">
                                <input type="radio" name="mode" value="migrate" @checked(! $backup['exists'] || $oldOr('mode', $database['mode'] ?? ($backup['exists'] ? 'backup' : 'migrate')) === 'migrate')>
                                <span><strong>Run migrations and seeders</strong><br><span class="muted">Builds empty tables with only the basic categories and plans, without the bundled settings.</span></span>
                            </label>
                            <label class="option">
                                <input type="checkbox" name="overwrite" value="1" @checked(old('overwrite', $database['overwrite'] ?? false))>
                                <span><strong>Replace existing tables</strong><br><span class="muted">Leave this off to preserve existing data. Enable only when you intentionally want this installer to drop every table in the selected database before installing.</span></span>
                            </label>

                            @if(is_array($databaseTest))
                                <div class="notice ok" style="margin-top: 16px;">
                                    <strong>Last database test passed</strong>
                                    <span>{{ $databaseTest['version'] }}</span>
                                </div>
                            @endif

                            <div class="actions">
                                <a href="{{ $install('filesystem') }}" class="btn secondary">Back</a>
                                <button class="btn primary" type="submit">Test and Continue</button>
                            </div>
                        </div>
                    </aside>
                </form>
            @elseif($step === 'admin')
                <form method="POST" action="{{ $post('admin') }}" class="grid">
                    @csrf
                    <div class="panel">
                        <div class="panel-head">
                            <div>
                                <h2>Admin Account</h2>
                                <p class="muted tight">This account becomes the first full admin after the database is imported.</p>
                            </div>
                            <span class="badge info">Owner</span>
                        </div>
                        <div class="panel-body form-grid">
                            <div class="field">
                                <label for="app_name">Site name</label>
                                <input id="app_name" name="app_name" value="{{ old('app_name', $app['name'] ?? 'AVDBCMS') }}" required>
                            </div>
                            <div class="field">
                                <label for="app_url">Domain / APP_URL</label>
                                <input id="app_url" name="app_url" value="{{ old('app_url', $app['url'] ?? $detectedUrl) }}" required>
                            </div>
                            <div class="field">
                                <label for="admin_username">Username</label>
                                <input id="admin_username" name="username" value="{{ old('username', $admin['username'] ?? 'admin') }}" required>
                            </div>
                            <div class="field">
                                <label for="admin_email">Email</label>
                                <input id="admin_email" type="email" name="email" value="{{ old('email', $admin['email'] ?? '') }}" required>
                            </div>
                            <div class="field">
                                <label for="admin_password">Password</label>
                                <input id="admin_password" type="password" name="password" required>
                            </div>
                            <div class="field">
                                <label for="password_confirmation">Confirm password</label>
                                <input id="password_confirmation" type="password" name="password_confirmation" required>
                            </div>
                        </div>
                    </div>

                    <aside class="panel">
                        <div class="panel-head">
                            <div>
                                <h3>Email Defaults</h3>
                                <p class="muted tight">Existing package SMTP values are prefilled from `.env` when available.</p>
                            </div>
                            <span class="badge info">Mail</span>
                        </div>
                        <div class="panel-body form-grid">
                            <div class="field">
                                <label for="mail_mailer">Mailer</label>
                                <select id="mail_mailer" name="mail_mailer">
                                    @foreach(['log' => 'Log only', 'smtp' => 'SMTP', 'sendmail' => 'Sendmail'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('mail_mailer', $app['mail_mailer'] ?? 'log') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label for="mail_from_address">From email</label>
                                <input id="mail_from_address" type="email" name="mail_from_address" value="{{ old('mail_from_address', $app['mail_from_address'] ?? '') }}" aria-describedby="mail_from_hint">
                                <small id="mail_from_hint" class="muted">Empty: your admin email is used. With SMTP, use an address of your mail account's domain.</small>
                            </div>
                            <div class="field">
                                <label for="mail_host">SMTP host</label>
                                <input id="mail_host" name="mail_host" value="{{ old('mail_host', $app['mail_host'] ?? '') }}">
                            </div>
                            <div class="field">
                                <label for="mail_port">SMTP port</label>
                                <input id="mail_port" name="mail_port" value="{{ old('mail_port', $app['mail_port'] ?? '587') }}">
                            </div>
                            <div class="field">
                                <label for="mail_username">SMTP username</label>
                                <input id="mail_username" name="mail_username" value="{{ old('mail_username', $app['mail_username'] ?? '') }}">
                            </div>
                            <div class="field">
                                <label for="mail_password">SMTP password</label>
                                <input id="mail_password" type="password" name="mail_password" value="{{ old('mail_password', $app['mail_password'] ?? '') }}">
                            </div>
                            <div class="field span-2">
                                <label for="mail_encryption">Encryption</label>
                                <select id="mail_encryption" name="mail_encryption">
                                    @foreach(['' => 'None', 'tls' => 'TLS', 'ssl' => 'SSL'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('mail_encryption', $app['mail_encryption'] ?? '') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="span-2 actions">
                                <a href="{{ $install('database') }}" class="btn secondary">Back</a>
                                <button class="btn primary" type="submit">Continue</button>
                            </div>
                        </div>
                    </aside>
                </form>
            @elseif($step === 'install')
                <section class="grid">
                    <div class="panel">
                        <div class="panel-head">
                            <div>
                                <h2>Ready to Install</h2>
                                <p class="muted tight">Review the final values before the installer writes `.env`, imports the database, and locks setup.</p>
                            </div>
                            <span class="badge bad">Final step</span>
                        </div>
                        <div class="panel-body">
                            <dl class="summary">
                                <dt>Site</dt><dd>{{ $app['name'] ?? 'AVDBCMS' }}</dd>
                                <dt>APP_URL</dt><dd>{{ $app['url'] ?? $detectedUrl }}</dd>
                                <dt>Database</dt><dd>{{ $database['database'] ?? '-' }} on {{ $database['host'] ?? '-' }}:{{ $database['port'] ?? '3306' }}</dd>
                                <dt>Import</dt><dd>{{ ($database['mode'] ?? ($backup['exists'] ? 'backup' : 'migrate')) === 'backup' ? 'SQL backup: '.$backup['filename'] : 'Migrations and seeders' }}</dd>
                                <dt>Admin</dt><dd>{{ $admin['username'] ?? '-' }} / {{ $admin['email'] ?? '-' }}</dd>
                            </dl>
                            @if(is_array($run ?? null) && ! in_array($run['status'] ?? '', ['missing', 'finished'], true))
                                {{-- BI-RV-01: the progress page goes on only with the settings the installation started with. --}}
                                @if($runMatches ?? false)
                                    <div class="notice warn" style="margin: 18px 0 0;">
                                        <strong>This installation is already in progress ({{ (int) $run['percent'] }}%)</strong>
                                        <span>Install Now goes on with it where it stopped. <a href="{{ $install('installing') }}">See its progress</a></span>
                                    </div>
                                @else
                                    <div class="notice warn" style="margin: 18px 0 0;" data-run-other-settings>
                                        <strong>An installation with other database settings is in progress ({{ (int) $run['percent'] }}%)</strong>
                                        <span>Install Now gives it up and starts a new installation with the settings above. To go on with it instead, enter its settings again in the <a href="{{ $install('database') }}">Database step</a>.</span>
                                    </div>
                                @endif
                            @endif
                            <form method="POST" action="{{ $post('install') }}">
                                @csrf
                                @if($database['overwrite'] ?? false)
                                    <label class="option" style="margin-bottom: 16px;">
                                        <input type="checkbox" name="confirm_overwrite" value="1" required>
                                        <span><strong>Confirm database replacement</strong><br><span class="muted">I understand that installation will remove every existing table and view in the selected database.</span></span>
                                    </label>
                                @endif
                                <div class="actions">
                                    <a href="{{ $install('admin') }}" class="btn secondary">Back</a>
                                    <button class="btn danger" type="submit">Install Now</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <aside class="panel">
                        <div class="panel-head">
                            <h3>What Will Happen</h3>
                            <span class="badge info">Automatic</span>
                        </div>
                        <div class="panel-body check-list">
                            <article class="check-card"><div><div class="check-title"><strong>Database</strong><span class="badge ok">Import</span></div><div class="check-help">The detected SQL backup is restored unless migrations were selected, in short steps with a progress bar: a host's time limit for one request cannot cut the installation off.</div></div></article>
                            <article class="check-card"><div><div class="check-title"><strong>Admin</strong><span class="badge ok">Create</span></div><div class="check-help">The selected admin user is created or updated and email verified.</div></div></article>
                            <article class="check-card"><div><div class="check-title"><strong>.env</strong><span class="badge ok">Write</span></div><div class="check-help">Production values, APP_KEY, APP_URL, database, queue, session, mail, and preserved service/API defaults are written last, after the database and the admin account are ready.</div></div></article>
                            <article class="check-card"><div><div class="check-title"><strong>Security</strong><span class="badge ok">Lock</span></div><div class="check-help"><code>APP_INSTALLED=true</code> and <code>storage/app/installed.json</code> prevent public reinstall access.</div></div></article>
                        </div>
                    </aside>
                </section>
            @endif

            {{-- Phones and tablets: the help card after the step's form (the hero's copy is hidden there). --}}
            <aside class="support-card bottom-help" aria-label="Need help during install?">
                <strong>Need help during install?</strong>
                <div class="support-links">
                    <a href="{{ \App\Support\TelegramLinks::GROUP_URL }}" target="_blank" rel="noopener">
                        <span>Telegram group</span>
                        {{ \App\Support\TelegramLinks::GROUP_TEXT }}
                    </a>
                    <a href="{{ \App\Support\TelegramLinks::SUPPORT_URL }}" target="_blank" rel="noopener">
                        <span>Telegram support</span>
                        {{ \App\Support\TelegramLinks::SUPPORT_HANDLE }}
                    </a>
                </div>
            </aside>
        </main>
    </div>
</div>

<script>
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            const target = document.getElementById(button.dataset.copy);
            if (!target) return;

            const text = target.innerText;
            try {
                await navigator.clipboard.writeText(text);
                const original = button.innerText;
                button.innerText = 'Copied';
                window.setTimeout(() => { button.innerText = original; }, 1400);
            } catch (error) {
                const range = document.createRange();
                range.selectNodeContents(target);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
            }
        });
    });

    // The installation runs in short steps (R1B-001): this loop asks for the next one until the work is done, then sends
    // the page's own form, whose answer is the result page (that request writes .env and the lock). A step the server
    // ended early (502/503/504, no answer) is simply asked for again: the server finds out where it stopped.
    (() => {
        const root = document.querySelector('[data-install-run]');
        if (!root || !window.fetch) return;

        const form = root.querySelector('[data-run-form]');
        const button = root.querySelector('[data-run-continue]');
        const retryInput = root.querySelector('[data-run-retry]');
        const bar = root.querySelector('[data-run-bar]');
        const badge = root.querySelector('[data-run-badge]');
        const statusLine = root.querySelector('[data-run-status]');
        const phaseList = root.querySelector('[data-run-phases]');
        const errorBox = root.querySelector('[data-run-error]');
        const errorTitle = root.querySelector('[data-run-error-title]');
        const errorText = root.querySelector('[data-run-error-text]');
        const notes = root.querySelector('[data-run-notes]');
        const back = root.querySelector('[data-run-back]');
        const states = {done: 'Done', current: 'Working', waiting: 'Waiting', failed: 'Stopped'};
        let misses = 0;
        let working = false;

        const element = (tag, className, text) => {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        };

        const render = (progress) => {
            if (!progress || !Array.isArray(progress.phases) || progress.phases.length === 0) return;
            const percent = Math.max(0, Math.min(100, Number(progress.percent) || 0));
            bar.setAttribute('aria-valuenow', String(percent));
            bar.firstElementChild.style.width = percent + '%';
            badge.textContent = percent + '%';
            phaseList.replaceChildren(...progress.phases.map((phase, index) => {
                const item = element('li', 'phase ' + phase.state);
                const mark = element('span', 'phase-mark', phase.state === 'done' ? 'OK' : (phase.state === 'failed' ? '!' : String(index + 1)));
                mark.setAttribute('aria-hidden', 'true');
                item.append(mark, element('span', 'phase-label', phase.label), element('span', 'phase-state', states[phase.state] || ''), element('span', 'phase-detail', phase.detail || ''));
                return item;
            }));
            notes.replaceChildren(...(progress.notes || []).map((note) => element('li', '', note)));
            const current = progress.phases.find((phase) => phase.state === 'current');
            if (progress.status === 'working' && current) {
                statusLine.textContent = 'Working on: ' + current.label + (current.detail ? ' (' + current.detail + ')' : '') + '...';
            }
        };

        const poll = async () => {
            try {
                const answer = await fetch(root.dataset.statusUrl, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
                if (working && answer.ok && (answer.headers.get('content-type') || '').includes('json')) render(await answer.json());
            } catch (error) {
                // the next poll or the step's own answer updates the page
            }
        };

        // The database settings of this browser are not the ones the installation started with (BI-RV-01): nothing was
        // done; the way on is the Install step (Install Now starts again) or the run's own settings in the Database step.
        const changed = (progress) => {
            working = false;
            render(progress);
            errorBox.hidden = false;
            errorTitle.textContent = 'The database settings changed';
            errorText.textContent = (progress && progress.error) || 'The database settings of this browser changed.';
            back.hidden = false;
            back.textContent = 'Back to Install';
            back.href = root.dataset.installStepUrl;
            button.hidden = true;
            statusLine.textContent = 'Nothing was done: the database settings of this browser changed. Read the message below.';
        };

        const stopped = (progress, message) => {
            working = false;
            render(progress);
            errorBox.hidden = false;
            errorTitle.textContent = 'The installation stopped';
            errorText.textContent = (progress && progress.error) || message || 'The installation stopped.';
            back.hidden = false;
            button.hidden = false;
            button.textContent = 'Try Again';
            retryInput.value = '1';
            statusLine.textContent = 'The installation stopped. Read the message below, then try again.';
        };

        // The site is installed, but the result page could not come (the server ended the last step after it had written
        // .env: this page's session belonged to the old key). The installer's own status says "installed".
        const installed = () => {
            working = false;
            const notice = element('div', 'notice ok');
            notice.append(element('strong', '', 'Installation complete'),
                element('span', '', 'The site is installed and the installer has locked itself. The server ended the last step before it could show the result page; the cron line for the scheduler is in Tools > Background jobs > Scheduled tasks of the admin panel.'));
            const actions = element('div', 'actions');
            const login = element('a', 'btn secondary', 'Login');
            login.href = root.dataset.loginUrl;
            const admin = element('a', 'btn primary', 'Open Admin');
            admin.href = root.dataset.adminUrl;
            actions.append(login, admin);
            const panel = element('div', 'panel pad');
            panel.append(notice, actions);
            root.replaceChildren(panel);
            root.className = 'grid single';
            const last = document.querySelector('.stepper .step.active');
            if (last) {
                last.classList.replace('active', 'done');
                last.removeAttribute('aria-current');
                last.querySelector('.dot').textContent = 'OK';
                last.querySelector('small').textContent = 'Completed';
            }
        };

        // The last step runs in the background too: its answer IS the result page; a step the server ended is asked
        // for again, and a lost session is checked against the installer's status.
        const finish = async () => {
            working = false;
            button.hidden = true;
            retryInput.value = '0';
            statusLine.textContent = 'Writing .env and locking the installer...';

            for (let attempt = 1; attempt <= 8; attempt++) {
                let answer = null;
                let html = '';
                try {
                    const body = new URLSearchParams();
                    body.set('_token', root.dataset.csrf);
                    answer = await fetch(root.dataset.runUrl, {
                        method: 'POST',
                        headers: {Accept: 'text/html', 'X-CSRF-TOKEN': root.dataset.csrf},
                        body,
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    html = await answer.text();
                } catch (error) {
                    answer = null;
                }

                // the result page's own notice (this script names the attribute too, without the '>')
                if (answer && answer.ok && html.includes('data-install-finished>')) {
                    document.open();
                    document.write(html);
                    document.close();
                    return;
                }

                if (answer && answer.ok) {
                    return step(false); // more work came up (e.g. a step of another tab): the loop goes on
                }

                if (answer && [403, 404, 419].includes(answer.status)) {
                    try {
                        const status = await fetch(root.dataset.statusUrl, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
                        const state = (status.headers.get('content-type') || '').includes('json') ? await status.json() : null;
                        if (state && state.status === 'installed') return installed();
                    } catch (error) {
                        // fall through: the page says what it knows
                    }
                    stopped(null, 'This page lost its session. Open the installer again to see where the installation stands.');
                    back.textContent = 'Open the Installer';
                    back.href = root.dataset.installUrl;
                    button.hidden = true;
                    return;
                }

                statusLine.textContent = 'The server ended the last step early (' + (answer ? 'HTTP ' + answer.status : 'no answer') + '). Trying again...';
                await new Promise((resolve) => window.setTimeout(resolve, Math.min(1000 * attempt, 5000)));
            }

            button.hidden = false;
            button.textContent = 'Finish Installation';
            statusLine.textContent = 'The last step did not answer. Click Finish Installation to try again.';
        };

        const step = async (retry) => {
            working = true;
            errorBox.hidden = true;
            back.hidden = true;
            button.hidden = true;
            const poller = window.setInterval(poll, 2000);
            let answer = null;
            let progress = null;

            try {
                const body = new URLSearchParams();
                body.set('_token', root.dataset.csrf);
                if (retry) body.set('retry', '1');
                answer = await fetch(root.dataset.runUrl, {
                    method: 'POST',
                    headers: {Accept: 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf, 'X-Requested-With': 'XMLHttpRequest'},
                    body,
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if ((answer.headers.get('content-type') || '').includes('json')) progress = await answer.json();
            } catch (error) {
                progress = null;
            }

            window.clearInterval(poller);

            if (answer && answer.ok && progress) {
                misses = 0;
                render(progress);
                if (progress.status === 'ready') return finish();
                if (progress.status === 'changed') return changed(progress);
                if (progress.status === 'failed') return stopped(progress);
                if (progress.status === 'missing' || progress.status === 'not_owner') {
                    return stopped(progress, 'This page no longer belongs to an installation in progress. Open the installer again.');
                }
                window.setTimeout(() => step(false), progress.status === 'busy' ? 2000 : 30);
                return;
            }

            if (answer && [403, 404, 419, 422].includes(answer.status)) {
                stopped(progress, (progress && progress.error) || 'This page lost its session: the installation may have finished in another tab, or the page was open too long. Open the installer again.');
                back.textContent = 'Open the Installer';
                back.href = root.dataset.installUrl;
                button.hidden = true;
                return;
            }

            misses++;
            statusLine.textContent = 'The server ended the last step early (' + (answer ? 'HTTP ' + answer.status : 'no answer') + '). The installer goes on where it stopped...';
            if (misses > 40) {
                working = false;
                button.hidden = false;
                button.textContent = 'Continue Installation';
                retryInput.value = '0';
                statusLine.textContent = 'The server did not answer ' + misses + ' times in a row. Check that the site is reachable, then click Continue Installation.';
                return;
            }
            window.setTimeout(() => step(false), Math.min(1000 * misses, 5000));
        };

        button.addEventListener('click', (event) => {
            if (button.textContent.trim() === 'Finish Installation') return;
            event.preventDefault();
            step(retryInput.value === '1');
        });

        if (root.dataset.status === 'ready') {
            finish();
        } else if (root.dataset.status === 'failed') {
            button.hidden = false;
        } else if (root.dataset.status !== 'changed') {
            // "changed": the page already says why nothing runs and where to go on (the Install step).
            step(false);
        }
    })();
</script>
</body>
</html>
