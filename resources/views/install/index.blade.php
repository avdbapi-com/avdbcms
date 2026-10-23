<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
        code { color: #d9e4ff; word-break: break-all; }
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
            justify-content: space-between;
            gap: 12px;
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

        @media (max-width: 980px) {
            .hero, .grid, .grid.equal { grid-template-columns: 1fr; }
            .hero-aside { min-width: 0; }
            .stepper { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .step { min-height: 68px; }
            .mini-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 640px) {
            .wrap { width: min(100% - 18px, 1220px); margin: 9px auto; }
            .hero, .body { padding: 20px; }
            .panel-head, .panel-body, .panel.pad { padding: 17px; }
            .stepper { grid-template-columns: 1fr; }
            .step:not(:last-child)::after { display: none; }
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
    $activeIndex = max(0, array_search($step === 'finished' ? 'install' : $step, $stepKeys, true));
    $path = trim(request()->path(), '/');
    $pfx = !empty($_SERVER['AVDBCLIP_ROOT_FALLBACK'])
        || !empty($_SERVER['AVDBCLIP_INDEX_PHP_PREFIX'])
        || str_starts_with($path, 'index.php/install')
        ? '/index.php'
        : '';
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
        <header class="hero">
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
                    The installer detects your environment, repairs safe local issues, imports the bundled database, writes `.env`, creates the admin account, and locks itself.
                </p>
            </div>

            <aside class="hero-aside" aria-label="Detected environment">
                <div class="status-tile"><span>Domain</span><span>{{ $detectedUrl }}</span></div>
                <div class="status-tile"><span>Web server</span><span>{{ $environment['web_server'] }}</span></div>
                <div class="status-tile"><span>SQL backup</span><span class="{{ $backup['exists'] ? '' : 'muted' }}">{{ $backup['exists'] ? $backup['filename'] : 'Not bundled' }}</span></div>
                <div class="support-card">
                    <strong>Need help during install?</strong>
                    <div class="support-links">
                        <a href="https://t.me/avdb_api" target="_blank" rel="noopener">
                            <span>Telegram group</span>
                            t.me/avdb_api
                        </a>
                        <a href="https://t.me/Avdbapi_support" target="_blank" rel="noopener">
                            <span>Support</span>
                            @Avdbapi_support
                        </a>
                    </div>
                </div>
            </aside>
        </header>

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

        <main class="body">
            @if($errors->any())
                <div class="notice bad" role="alert">
                    <strong>Action needed</strong>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            @if($step === 'installed')
                <section class="grid">
                    <div class="panel pad">
                        <span class="badge ok">Locked</span>
                        <div class="spacer"></div>
                        <h2>Already Installed</h2>
                        <p class="muted tight">
                            The installer is locked because this application already has an admin account or an installer lock file.
                            This protects public installs from being overwritten.
                        </p>
                        <div class="notice warn" style="margin-top: 18px;">
                            <strong>Need to reinstall?</strong>
                            <span>Remove <code>storage/app/installed.json</code> and set <code>INSTALLER_ALLOW_REINSTALL=true</code> only when you intentionally want to run setup again.</span>
                        </div>
                        <div class="actions">
                            <a href="{{ url('/') }}" class="btn secondary">Open Site</a>
                            <a href="{{ url('/admin') }}" class="btn primary">Open Admin</a>
                        </div>
                    </div>
                    <aside class="panel pad">
                        <h3>Current Environment</h3>
                        <div class="spacer"></div>
                        <dl class="summary">
                            <dt>OS</dt><dd>{{ $environment['os'] }}</dd>
                            <dt>Panel</dt><dd>{{ $environment['panel'] }}</dd>
                            <dt>Project root</dt><dd>{{ $environment['project_root'] }}</dd>
                            <dt>Public path</dt><dd>{{ $environment['public_path'] }}</dd>
                        </dl>
                    </aside>
                </section>
            @elseif($step === 'finished')
                <div class="notice ok">
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
                        </div>
                    </div>

                    <aside class="panel pad">
                        <h3>Recommended After Install</h3>
                        <p class="muted">
                            Add one cron entry in your hosting panel so scheduled imports, queues, duration backfill, cleanup, and email tasks keep moving. Use the same PHP CLI version as the website.
                        </p>
                        <div class="config-wrap">
                            <div class="config-toolbar">
                                <span>Run every minute</span>
                                <button class="copy-btn" type="button" data-copy="cron-command">Copy</button>
                            </div>
                            <pre id="cron-command" class="config-snippet">{{ $schedulerCronCommand }}</pre>
                        </div>
                        <p class="footer-note">Run this command every minute as the domain user. If your panel has a separate PHP selector for cron, use the same PHP 8.3+ binary as the website.</p>
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
                        <p class="muted">New users should avoid 777 permissions unless a host specifically requires it temporarily. The safer target is writable by the web/PHP user.</p>
                        <div class="spacer"></div>
                        <div class="check-list">
                            <div class="check-card">
                                <div>
                                    <div class="check-title"><strong>Linux panels</strong><span class="badge info">Typical</span></div>
                                    <div class="check-help">Use the file manager to set <code>storage</code> and <code>bootstrap/cache</code> writable. On VPS, use the same owner as PHP-FPM.</div>
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
                                <p class="muted tight">Use migrations for a fresh installation. Restore a backup only when you have supplied one.</p>
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
                                <span><strong>Run migrations and seeders</strong><br><span class="muted">Use this for a clean empty install when no backup should be imported.</span></span>
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
                                <input id="mail_from_address" type="email" name="mail_from_address" value="{{ old('mail_from_address', $app['mail_from_address'] ?? '') }}">
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
                                <dt>Import</dt><dd>{{ ($database['mode'] ?? 'migrate') === 'backup' ? 'SQL backup: '.$backup['filename'] : 'Migrations and seeders' }}</dd>
                                <dt>Admin</dt><dd>{{ $admin['username'] ?? '-' }} / {{ $admin['email'] ?? '-' }}</dd>
                            </dl>
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
                            <article class="check-card"><div><div class="check-title"><strong>.env</strong><span class="badge ok">Write</span></div><div class="check-help">Production values, APP_KEY, APP_URL, database, queue, session, mail, and preserved service/API defaults are written.</div></div></article>
                            <article class="check-card"><div><div class="check-title"><strong>Database</strong><span class="badge ok">Import</span></div><div class="check-help">The detected SQL backup is restored unless migrations were selected.</div></div></article>
                            <article class="check-card"><div><div class="check-title"><strong>Admin</strong><span class="badge ok">Create</span></div><div class="check-help">The selected admin user is created or updated and email verified.</div></div></article>
                            <article class="check-card"><div><div class="check-title"><strong>Security</strong><span class="badge ok">Lock</span></div><div class="check-help"><code>APP_INSTALLED=true</code> and <code>storage/app/installed.json</code> prevent public reinstall access.</div></div></article>
                        </div>
                    </aside>
                </section>
            @endif
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
</script>
</body>
</html>
