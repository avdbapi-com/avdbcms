{{--
    Tools > Updates (F8): the AVDBCMS version of this site and when it was last updated; the newest release on GitHub,
    from the kept check (when nothing is kept yet, the section asks after the page appears: data-ad-defer with
    admin.updates.status?check=1); "Update to X.Y.Z" with its confirm dialog (password, the database backup); the
    progress of a running update; the result of the last one; the file backups with "Undo this update"; and why a
    one-click update cannot run here, with the manual way. Its script (resources/admin/updates/updates.ts) walks the
    steps of the update and reads its addresses and texts from #ad-updates-boot.
--}}
@use('App\Support\AdminNav')
@use('App\Support\AdminTheme')
@use('App\Support\AppVersion')
@use('Illuminate\Support\Carbon')
@extends('admin.updates.layout')

@section('title', __('Updates'))

@push('head')
    @vite('resources/admin/updates/updates.ts')
@endpush

@section('content')
    @php
        $tz = (string) config('app.timezone');
        $when = fn (?int $time): string => $time ? Carbon::createFromTimestamp($time)->setTimezone($tz)->translatedFormat('j M Y, H:i') : '—';
        $ago = fn (?int $time): string => $time ? Carbon::createFromTimestamp($time)->diffForHumans() : '—';
        $mb = fn (?int $bytes): string => number_format(max(0, (int) $bytes) / 1048576, 1).' MB';
        $checkState = $check['state'] ?? null;
        $checking = $check === null && $problem === null;
        $version = $release['version'] ?? null;
        $published = $release !== null && is_string($release['published_at'] ?? null)
            ? rescue(fn () => Carbon::parse($release['published_at'])->setTimezone($tz)->translatedFormat('j M Y'), null, false)
            : null;
        // Built from the constants, never from the answer: the only release page this site links to.
        $releasePage = $version !== null ? AppVersion::WEB_BASE.'/'.AppVersion::REPOSITORY.'/releases/tag/'.AppVersion::tag($version) : null;
        $installable = $ops && $blockers === [] && $checkState === 'available' && $release !== null && $plan === null;
        $undoable = $ops && $plan === null ? collect($backups)->firstWhere('undo', true) : null;
        $lockedText = $lockedFor > 0
            ? trans_choice('Too many wrong passwords. Try again in 1 minute.|Too many wrong passwords. Try again in :count minutes.', (int) ceil($lockedFor / 60), ['count' => (int) ceil($lockedFor / 60)])
            : null;
        $running = $plan !== null && $plan['mine'] && ! $plan['stalled'];
        $progress = $plan['progress'] ?? null;
        $percent = is_array($progress) && ($progress['total'] ?? 0) > 0 ? min(100, (int) floor($progress['done'] * 100 / $progress['total'])) : 0;
        $planTitle = fn (?array $p): string => $p === null ? '' : ($p['kind'] === 'undo'
            ? __('Going back to AVDBCMS :version', ['version' => $p['version']])
            : __('Installing AVDBCMS :version', ['version' => $p['version']]));
        // Undo this update compares the files before the site goes down: until then it can be called off too.
        $cancelConfirm = ($plan['kind'] ?? 'update') === 'undo' ? [
            'data-ad-confirm' => __('Cancel the undo?'),
            'data-ad-confirm-body' => __('Nothing on your site was changed yet. Your site keeps the new version.'),
            'data-ad-confirm-label' => __('Cancel the undo'),
        ] : [
            'data-ad-confirm' => __('Cancel the update?'),
            'data-ad-confirm-body' => __('Nothing on your site was changed. The downloaded files are deleted; a database backup it already made stays on the Backups page.'),
            'data-ad-confirm-label' => __('Cancel the update'),
        ];
        $boot = [
            'urls' => [
                'status' => route('admin.updates.status', [], false),
                'step' => route('admin.updates.step', [], false),
                'swap' => route('admin.updates.swap', [], false),
                'finish' => route('admin.updates.finish', [], false),
                'cancel' => route('admin.updates.cancel', [], false),
            ],
            'plan' => $plan === null ? null : [
                'next' => $plan['next'], 'mine' => $plan['mine'], 'stalled' => $plan['stalled'], 'kind' => $plan['kind'],
                'version' => $plan['version'], 'waiting' => $plan['last_error'] !== null,
            ],
            // A running update of this administrator goes on by itself when the page opens again.
            'run' => $ops && $running && $plan['last_error'] === null && $plan['next'] !== null,
            'texts' => [
                'installing' => __('Installing AVDBCMS :version'),
                'undoing' => __('Going back to AVDBCMS :version'),
                'starting' => __('Starting the update...'),
                'retrying' => __('The connection to your site dropped. Trying again in :seconds seconds...'),
                'lost' => __('Your site did not answer. Check your connection, then click Continue.'),
            ],
        ];
    @endphp

    <div class="ad-upd" data-ad-updates>
        <x-admin.page-header page="tools.updates">
            <x-slot:actions>
                <form method="POST" action="{{ route('admin.updates.check') }}" data-ad-busy="{{ __('Checking...') }}">
                    @csrf
                    <button type="submit" class="ad-btn">{{ AdminTheme::icon('refresh') }}{{ __('Check now') }}</button>
                </form>
            </x-slot:actions>
        </x-admin.page-header>

        @if ($problem)
            <x-admin.callout type="danger" class="ad-console-block" role="alert">{{ $problem }}</x-admin.callout>
        @endif

        {{-- Why a one-click update cannot run here: operational tools off, no file list of the installed version, no zip engine. --}}
        @if ($blockers !== [])
            <div class="ad-callout is-warn ad-console-block" role="note">
                {{ AdminTheme::icon('alert') }}
                <div class="ad-callout-body">
                    <h3>{{ __('One-click updates cannot run on this site') }}</h3>
                    <ul class="ad-upd-reasons">
                        @foreach ($blockers as $why)
                            <li>{{ $why }}</li>
                        @endforeach
                    </ul>
                    <div class="ad-callout-actions">
                        <a class="ad-btn sm" href="{{ AppVersion::HELP_URL }}" target="_blank" rel="noopener">{{ AdminTheme::icon('help') }}{{ __('How to update by hand') }}</a>
                        @if ($releasePage)
                            <a class="ad-btn is-ghost sm" href="{{ $releasePage }}" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __('Release page on GitHub') }}</a>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- Another administrator's update, or one nobody moved for 30 minutes. --}}
        @if ($plan !== null && $plan['stalled'])
            <x-admin.callout type="warn" class="ad-console-block" :title="__('An update to :version stopped :time. Continue it or cancel it first.', ['version' => $plan['version'], 'time' => $ago($plan['step_at'])])" role="status">
                {{ $plan['label'] }}
                @if ($ops)
                    <x-slot:actions>
                        <form method="POST" action="{{ route('admin.updates.'.($plan['next'] ?? 'step')) }}" data-ad-updates-continue="{{ $plan['next'] ?? 'step' }}">
                            @csrf
                            <button type="submit" class="ad-btn is-primary sm">{{ __('Continue') }}</button>
                        </form>
                        @if ($plan['cancellable'])
                            <form method="POST" action="{{ route('admin.updates.cancel') }}" data-ad-updates-cancel @foreach ($cancelConfirm as $attribute => $value) {{ $attribute }}="{{ $value }}" @endforeach>
                                @csrf
                                <button type="submit" class="ad-btn is-ghost sm">{{ $cancelConfirm['data-ad-confirm-label'] }}</button>
                            </form>
                        @endif
                    </x-slot:actions>
                @endif
            </x-admin.callout>
        @elseif ($plan !== null && ! $plan['mine'])
            <x-admin.callout class="ad-console-block" :title="$plan['busy']" icon="clock" role="status">
                <x-slot:actions>
                    <button type="button" class="ad-btn sm" data-ad-updates-watch>{{ __('Show progress') }}</button>
                </x-slot:actions>
            </x-admin.callout>
        @endif

        {{-- The progress of a running update or undo (the script shows it when one starts on this page). --}}
        <section class="ad-panel ad-console-block ad-upd-progress" data-ad-updates-progress aria-labelledby="ad-upd-progress-t" @unless ($running) hidden @endunless>
            <div class="ad-panel-h">
                <span class="ad-sym is-accent" aria-hidden="true">{{ AdminTheme::icon('download') }}</span>
                <h2 id="ad-upd-progress-t" class="ad-home-grow" data-ad-updates-title>{{ $planTitle($plan) }}</h2>
            </div>
            <div class="ad-panel-b">
                <p class="ad-upd-label" data-ad-updates-label role="status" aria-live="polite">{{ $plan['label'] ?? '' }}</p>
                <progress class="ad-upd-bar" data-ad-updates-bar max="100" value="{{ $percent }}" aria-labelledby="ad-upd-progress-t"></progress>
                <p class="ad-small ad-muted">{{ __('Keep this page open until it says the update is installed.') }}</p>
                <p class="ad-error" data-ad-updates-error role="alert" @if (empty($plan['last_error'])) hidden @endif>{{ $plan['last_error'] ?? '' }}</p>
                <details class="ad-upd-log" data-ad-updates-log>
                    <summary>{{ __('Show details') }}</summary>
                    <pre data-ad-updates-log-lines>{{ $plan !== null ? implode("\n", $log) : '' }}</pre>
                </details>
            </div>
            @if ($ops)
                <div class="ad-panel-f">
                    <form method="POST" action="{{ route('admin.updates.'.($plan['next'] ?? 'step')) }}" data-ad-updates-continue="{{ $plan['next'] ?? 'step' }}" @if (empty($plan['last_error'])) hidden @endif>
                        @csrf
                        <button type="submit" class="ad-btn is-primary sm">{{ __('Continue') }}</button>
                    </form>
                    {{-- Another administrator's update can only be watched until it stalls (F8 §14). --}}
                    <form method="POST" action="{{ route('admin.updates.cancel') }}" data-ad-updates-cancel @foreach ($cancelConfirm as $attribute => $value) {{ $attribute }}="{{ $value }}" @endforeach @unless (($plan['cancellable'] ?? false) && $running) hidden @endunless>
                        @csrf
                        <button type="submit" class="ad-btn is-ghost sm">{{ $cancelConfirm['data-ad-confirm-label'] }}</button>
                    </form>
                </div>
            @endif
        </section>

        {{-- The last update or undo (for two weeks): what it kept, merged and replaced. --}}
        @if ($result !== null)
            @php
                $report = $result['report'];
                $tone = match ($result['status']) {
                    'done', 'undone' => 'ok',
                    'failed' => 'danger',
                    default => 'info',
                };
                $isUpdate = $result['kind'] === 'update';
            @endphp
            <section class="ad-panel ad-console-block ad-upd-result" aria-labelledby="ad-upd-result-t">
                <div class="ad-panel-h">
                    <span class="ad-sym is-{{ $tone === 'info' ? 'accent' : $tone }}" aria-hidden="true">{{ AdminTheme::icon(['ok' => 'check-circle', 'danger' => 'alert-circle'][$tone] ?? 'info') }}</span>
                    <div class="ad-home-grow">
                        <h2 id="ad-upd-result-t">{{ $result['status'] === 'failed' ? $result['title'] : $result['label'] }}</h2>
                        <p>{{ $when($result['finished_at'] ?? $result['step_at']) }}</p>
                    </div>
                    @if ($result['status'] === 'done' && $isUpdate)
                        <a class="ad-btn is-ghost sm" href="{{ AppVersion::changelogUrl($result['version']) }}" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __("What's new") }}</a>
                        <a class="ad-btn sm" href="{{ route('admin.index') }}">{{ AdminTheme::icon('home') }}{{ __('Home') }}</a>
                    @endif
                </div>
                <div class="ad-panel-b ad-stack">
                    @if ($result['status'] === 'failed')
                        <p>{{ $result['label'] }}</p>
                    @endif
                    @if ($result['status'] === 'done' && $isUpdate)
                        <p class="ad-muted">
                            {{ __('Files replaced: :replaced. Added: :added. Removed: :removed.', ['replaced' => number_format($report['replaced']), 'added' => number_format($report['added']), 'removed' => number_format($report['removed'])]) }}
                            @if ($result['database_backup'])
                                {{ __('Database backup: :name', ['name' => $result['database_backup']['name']]) }}
                            @endif
                        </p>
                    @endif
                    @if ($report['kept'] !== [])
                        <div>
                            <h3 class="ad-h3">{{ $isUpdate ? __('You changed these files, so the update kept your version. Compare them with the new copy.') : __('These files changed after the update, so the undo left them as they are.') }}</h3>
                            <ul class="ad-upd-files">
                                @foreach ($report['kept'] as $file)
                                    <li><code>{{ $file['path'] }}</code>@if ($file['copy']) {{ AdminTheme::icon('arrow-right', 'sm flip-rtl') }} <code>{{ $file['copy'] }}</code>@endif</li>
                                @endforeach
                            </ul>
                            @if (! $isUpdate && $result['kept_backup'])
                                {{-- Their copies from before the update are the only ones: the undo kept them (Updater::keepUndoneCopies()). --}}
                                <p class="ad-small ad-muted">{{ __('Their copies from before the update stay in :folder until the next update.', ['folder' => 'storage/app/updates/backups/'.$result['kept_backup'].'/files/']) }}</p>
                            @endif
                        </div>
                    @endif
                    @if ($report['merged'] !== [])
                        <div>
                            <h3 class="ad-h3">{{ __('These language files had texts you changed. Your texts stay; the new texts were added.') }}</h3>
                            <ul class="ad-upd-files">
                                @foreach ($report['merged'] as $file)
                                    <li><code>{{ $file['path'] }}</code> <span class="ad-muted">{{ trans_choice('1 new text added|:count new texts added', $file['added'], ['count' => number_format($file['added'])]) }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($report['overwritten'] !== [])
                        <div>
                            <h3 class="ad-h3">{{ __('Your changes to these files were replaced. The old copies are in the update backup.') }}</h3>
                            <ul class="ad-upd-files">
                                @foreach ($report['overwritten'] as $path)
                                    <li><code>{{ $path }}</code></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($result['kept'] !== [])
                        <div>
                            <h3 class="ad-h3">{{ __('These files changed while the update ran, so they were left as they are.') }}</h3>
                            <ul class="ad-upd-files">
                                @foreach ($result['kept'] as $path)
                                    <li><code>{{ $path }}</code></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <details class="ad-upd-log" data-ad-updates-log>
                        <summary>{{ __('Show details') }}</summary>
                        <pre data-ad-updates-log-lines>{{ implode("\n", $log) }}</pre>
                    </details>
                </div>
            </section>
        @endif

        {{-- This site and the newest release. Nothing kept yet: asked after the page appears (status?check=1). --}}
        <div class="ad-upd-release" data-ad-defer="updates-release" @if ($checking) data-ad-defer-probes="{{ route('admin.updates.status', ['check' => 1]) }}" @endif>
            <section class="ad-panel ad-console-block" aria-labelledby="ad-upd-site-t">
                <div class="ad-panel-h">
                    <h2 id="ad-upd-site-t" class="ad-home-grow">{{ __('This site') }}</h2>
                    @if ($checkState === 'available')
                        <x-admin.badge type="accent">{{ __('Update available') }}</x-admin.badge>
                    @elseif ($checkState === 'current')
                        <x-admin.badge type="ok">{{ __('Up to date') }}</x-admin.badge>
                    @endif
                </div>
                <div class="ad-panel-b">
                    <dl class="ad-upd-facts">
                        <div>
                            <dt>{{ __('Installed version') }}</dt>
                            <dd>AVDBCMS {{ $current }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Last updated') }}</dt>
                            <dd>{{ $state !== null ? __(':date, from AVDBCMS :version', ['date' => $when((int) ($state['updated_at'] ?? 0)), 'version' => $state['previous_version'] ?? '?']) : '—' }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Last check') }}</dt>
                            <dd>{{ $checking ? __('Asking GitHub for the newest version...') : $ago(isset($check['checked_at']) ? (int) $check['checked_at'] : null) }}</dd>
                        </div>
                    </dl>
                    @if ($checkState === 'available' && $version !== null)
                        <p class="ad-upd-status"><span class="ad-state is-info">{{ __('AVDBCMS :version is available', ['version' => $version]) }}</span></p>
                    @elseif (! $checking && $checkMessage)
                        <p class="ad-upd-status"><span @class(['ad-state', 'is-ok' => $checkState === 'current', 'is-warn' => $checkState === 'error', 'is-off' => $checkState === 'none'])>{{ $checkMessage }}</span></p>
                    @endif
                </div>
            </section>

            @if ($release !== null)
                <section class="ad-panel ad-console-block ad-upd-new" aria-labelledby="ad-upd-new-t">
                    <div class="ad-panel-h">
                        <span class="ad-sym is-accent" aria-hidden="true">{{ AdminTheme::icon('sparkle') }}</span>
                        <div class="ad-home-grow">
                            <h2 id="ad-upd-new-t">{{ ($release['name'] ?? '') !== '' ? $release['name'] : 'AVDBCMS '.$version }}</h2>
                            @if ($published)
                                <p>{{ __('Published :date', ['date' => $published]) }}</p>
                            @endif
                        </div>
                        <a class="ad-btn is-ghost sm" href="{{ AppVersion::changelogUrl($version) }}" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __("What's new") }}</a>
                    </div>
                    @if (trim((string) ($release['body'] ?? '')) !== '')
                        {{-- GitHub's notes as plain text (escaped; never HTML). --}}
                        <div class="ad-panel-b">
                            <details class="ad-upd-notes">
                                <summary>{{ __('Release notes') }}</summary>
                                <div class="ad-upd-notes-text">{{ $release['body'] }}</div>
                            </details>
                        </div>
                    @endif
                    <div class="ad-panel-f">
                        @if ($installable)
                            <button type="button" class="ad-btn is-primary" data-ad-updates-open="ad-upd-install" @disabled($lockedFor > 0)>{{ AdminTheme::icon('download') }}{{ __('Update to :version', ['version' => $version]) }}</button>
                            @if ($lockedText)
                                <span class="ad-small ad-muted">{{ $lockedText }}</span>
                            @endif
                        @endif
                        <span class="ad-spacer"></span>
                        <a class="ad-link ad-small" href="{{ $releasePage }}" target="_blank" rel="noopener">{{ __('Release page on GitHub') }}</a>
                    </div>
                </section>

                @if ($installable)
                    <dialog id="ad-upd-install" class="ad-dialog ad-upd-dialog" aria-labelledby="ad-upd-install-t">
                        <form method="POST" action="{{ route('admin.updates.start') }}" data-ad-updates-form="start" data-ad-updates-version="{{ $version }}">
                            @csrf
                            <div class="ad-dialog-b">
                                <span class="ad-sym is-accent" aria-hidden="true">{{ AdminTheme::icon('download') }}</span>
                                <div class="ad-dialog-text">
                                    <h2 id="ad-upd-install-t">{{ __('Update to AVDBCMS :version?', ['version' => $version]) }}</h2>
                                    <p>{{ __('This is what happens:') }}</p>
                                    <ol class="ad-upd-what">
                                        <li>{{ ! empty($release['zip']['size'])
                                            ? __('AVDBCMS downloads the update from GitHub (:size) and checks that it arrived complete.', ['size' => $mb((int) $release['zip']['size'])])
                                            : __('AVDBCMS downloads the update from GitHub and checks that it arrived complete.') }}</li>
                                        <li data-ad-updates-dump>{{ __('A backup of your database is made first. It appears on the Backups page.') }}</li>
                                        <li>{{ __('The files that changed are replaced. Visitors see a short "being updated" page meanwhile, usually for less than a minute. The old files are kept, so you can undo the update.') }}</li>
                                        <li>{{ __('The database changes of the new version run, as with Finish update.') }}</li>
                                    </ol>
                                    <p>{{ __('Your .env file, your uploads and your backups are not touched. Keep this page open until it says the update is installed.') }}</p>
                                    <div class="ad-upd-password">
                                        <label for="ad-upd-password">{{ __('Your password') }}</label>
                                        <input class="ad-input" type="password" id="ad-upd-password" name="password" autocomplete="current-password" required>
                                    </div>
                                    @if ($recentBackup)
                                        <label class="ad-upd-skip">
                                            <input type="checkbox" class="ad-check" name="skip_backup" value="1" data-ad-updates-skip>
                                            <span>{{ __('Skip the database backup') }}</span>
                                        </label>
                                        <p class="ad-help">{{ __('The Backups page already has a backup from :date: :name.', ['date' => $when((int) $recentBackup['date']), 'name' => $recentBackup['name']]) }}</p>
                                    @endif
                                    <p class="ad-error" data-ad-updates-form-error role="alert" hidden></p>
                                </div>
                            </div>
                            <div class="ad-dialog-f">
                                <button type="button" class="ad-btn" data-ad-updates-close>{{ __('Cancel') }}</button>
                                <button type="submit" class="ad-btn is-primary">{{ __('Update to :version', ['version' => $version]) }}</button>
                            </div>
                        </form>
                    </dialog>
                @endif
            @endif
        </div>

        {{-- The file backups of earlier updates: Undo (the newest, while the site runs its version) and Delete. --}}
        <section class="ad-panel ad-console-block" aria-labelledby="ad-upd-backups-t">
            <div class="ad-panel-h">
                <div class="ad-home-grow">
                    <h2 id="ad-upd-backups-t">{{ __('Update backups') }}</h2>
                    <p>{{ __('The files each update replaced, so it can be undone. The two newest are kept.') }}</p>
                </div>
            </div>
            @if ($backups === [])
                <div class="ad-panel-b"><p class="ad-muted">{{ __('No update backups yet.') }}</p></div>
            @else
                <ul class="ad-list">
                    @foreach ($backups as $backup)
                        <li>
                            <span class="ad-sym" aria-hidden="true">{{ AdminTheme::icon('archive') }}</span>
                            <span class="ad-li-text">
                                <b id="ad-upd-bk-{{ $loop->index }}">AVDBCMS {{ $backup['from'] }} {{ AdminTheme::icon('arrow-right', 'sm flip-rtl') }} {{ $backup['to'] }}</b>
                                <span>{{ $when((int) $backup['created_at']) }} · {{ $mb((int) $backup['size']) }}@if (! empty($backup['database_backup'])) · {{ __('Database backup: :name', ['name' => $backup['database_backup']]) }}@endif</span>
                            </span>
                            @if ($ops)
                                @if ($undoable !== null && $backup['id'] === $undoable['id'])
                                    <button type="button" class="ad-btn sm" data-ad-updates-open="ad-upd-undo" @disabled($lockedFor > 0)>{{ AdminTheme::icon('undo') }}{{ __('Undo this update') }}</button>
                                @endif
                                <form method="POST" action="{{ route('admin.updates.backups.delete', ['id' => $backup['id']]) }}"
                                      data-ad-confirm="{{ __('Delete this update backup?') }}"
                                      data-ad-confirm-body="{{ __('The files it holds are removed from the server. An update whose backup is gone cannot be undone.') }}"
                                      data-ad-confirm-label="{{ __('Delete backup') }}">
                                    @csrf
                                    <button type="submit" class="ad-btn is-ghost sm" aria-describedby="ad-upd-bk-{{ $loop->index }}">{{ AdminTheme::icon('trash') }}{{ __('Delete') }}</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($undoable !== null)
            <dialog id="ad-upd-undo" class="ad-dialog ad-upd-dialog" aria-labelledby="ad-upd-undo-t">
                <form method="POST" action="{{ route('admin.updates.undo') }}" data-ad-updates-form="undo" data-ad-updates-version="{{ $undoable['from'] }}">
                    @csrf
                    <div class="ad-dialog-b">
                        <span class="ad-sym is-danger" aria-hidden="true">{{ AdminTheme::icon('undo') }}</span>
                        <div class="ad-dialog-text">
                            <h2 id="ad-upd-undo-t">{{ __('Undo the update to AVDBCMS :version?', ['version' => $undoable['to']]) }}</h2>
                            <p>{{ ! empty($undoable['database_backup'])
                                ? __("Your files go back to AVDBCMS :version. The database stays as it is now. If the old version shows errors, restore the database backup :name with your hosting panel's database tool.", ['version' => $undoable['from'], 'name' => $undoable['database_backup']])
                                : __('Your files go back to AVDBCMS :version. The database stays as it is now.', ['version' => $undoable['from']]) }}</p>
                            <p>{{ __('Files you changed after the update stay as they are. Visitors see a short "being updated" page meanwhile.') }}</p>
                            <div class="ad-upd-password">
                                <label for="ad-upd-undo-password">{{ __('Your password') }}</label>
                                <input class="ad-input" type="password" id="ad-upd-undo-password" name="password" autocomplete="current-password" required>
                            </div>
                            <p class="ad-error" data-ad-updates-form-error role="alert" hidden></p>
                        </div>
                    </div>
                    <div class="ad-dialog-f">
                        <button type="button" class="ad-btn" data-ad-updates-close>{{ __('Cancel') }}</button>
                        <button type="submit" class="ad-btn is-danger-solid">{{ __('Undo this update') }}</button>
                    </div>
                </form>
            </dialog>
        @endif

        <script type="application/json" id="ad-updates-boot">@json($boot)</script>
    </div>
@endsection
