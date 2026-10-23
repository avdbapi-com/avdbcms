{{--
    Home of the redesigned admin (the old admin keeps admin/index.blade.php): the update banner (Finish update, the
    look the upgrade moved, then a one-click update that runs or a newer AVDBCMS from the kept check), getting started,
    what needs the administrator, this week in numbers, the latest imports and comments. The slow server checks
    (scheduler, disk, health check, providers) come from the status probes: shown at once when cached, otherwise
    "Needs you" fills in after the page appears (data-ad-defer, modules/list-probes.ts).
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Home'))
@section('admin_page', 'home.overview')

@section('content')
    @php
        $waiting = array_keys(array_filter($probes, fn ($probe) => $probe === null));
        $found = array_filter($probes, fn ($probe) => $probe !== null && in_array($probe['status'], ['warn', 'danger'], true));
        $needCount = count($needs) + count($found);
        $doneSteps = count(array_filter($steps, fn ($step) => $step['done']));
        // $steps: [] when every step is done; $stepsHidden: the administrator hid the list ("Hide this list").
        $metric = fn (array $m): ?string => $m['before'] > 0
            ? (($m['week'] >= $m['before'] ? '+' : '-').abs((int) round(($m['week'] - $m['before']) / $m['before'] * 100)).'%')
            : null;
        $disk = $probes['disk'] ?? null;
        $statusLine = array_filter([
            config('site.robots_noindex', false) ? __('Hidden from Google.') : __('Visible on Google.'),
            config('site.maintenance_mode', false) ? __('Maintenance mode is on.') : __('Maintenance off.'),
            $disk !== null && isset($disk['data']['percent']) ? __('Disk :percent% used.', ['percent' => $disk['data']['percent']]) : null,
        ]);
    @endphp

    @if ($update['pending'] > 0)
        <x-admin.callout class="ad-home-update" :title="__('Finish the update')" role="status">
            {{ trans_choice('A new version is uploaded. 1 database change is waiting - your site keeps working while you do this.|A new version is uploaded. :count database changes are waiting - your site keeps working while you do this.', $update['pending'], ['count' => $update['pending']]) }}
            @if ($update['moves_look'])
                {{ __('Your look moves to the new theme collection; you can restore the old one for 30 days.') }}
            @endif
            <x-slot:actions>
                <form method="POST" action="{{ route('admin.update.finish') }}" data-ad-busy="{{ __('Finishing the update...') }}">
                    @csrf
                    <button type="submit" class="ad-btn is-primary sm">{{ __('Finish update') }}</button>
                </form>
            </x-slot:actions>
        </x-admin.callout>
    @elseif ($update['look'] === 'moved')
        <x-admin.callout class="ad-home-update" :title="__('Your look moved to the new theme collection')" icon="palette" role="status">
            {{ __('Not what you expected? You can restore your previous look until :date.', ['date' => \Illuminate\Support\Carbon::parse($update['snapshot']['expires_at'])->translatedFormat('j F Y')]) }}
            <x-slot:actions>
                {{-- Compare (migration plan step 10): the design history lists the update next to the look before it. --}}
                <a class="ad-btn is-ghost sm" href="{{ route('admin.appearance.history') }}">{{ __('See changes') }}</a>
                <form method="POST" action="{{ route('admin.update.restore-look') }}"
                      data-ad-confirm="{{ __('Restore your previous look?') }}"
                      data-ad-confirm-body="{{ __('Your site goes back to the theme, colours and layouts it had before the update. You can use the new look again from Home.') }}"
                      data-ad-confirm-label="{{ __('Restore my previous look') }}" data-ad-confirm-tone="primary">
                    @csrf
                    <button type="submit" class="ad-btn sm">{{ AdminTheme::icon('undo') }}{{ __('Restore my previous look') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.update.keep-look') }}">
                    @csrf
                    <button type="submit" class="ad-btn is-ghost sm">{{ __('Keep the new look') }}</button>
                </form>
            </x-slot:actions>
        </x-admin.callout>
    @elseif ($update['look'] === 'previous')
        <x-admin.callout class="ad-home-update" :title="__('Your site uses its previous look')" icon="palette" role="status">
            {{ __('The new theme collection is one click away. Your current look is kept, so you can come back to it.') }}
            <x-slot:actions>
                <form method="POST" action="{{ route('admin.update.new-look') }}">
                    @csrf
                    <button type="submit" class="ad-btn is-primary sm">{{ __('Use the new look') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.update.keep-look') }}">
                    @csrf
                    <input type="hidden" name="look" value="previous">
                    <button type="submit" class="ad-btn is-ghost sm">{{ __('Keep my previous look') }}</button>
                </form>
            </x-slot:actions>
        </x-admin.callout>
    @elseif ($release['running'] !== null)
        {{-- A one-click update runs (or stopped): Tools > Updates shows it and goes on from there. --}}
        <x-admin.callout class="ad-home-update" :title="$release['running']" icon="clock" role="status">
            <x-slot:actions>
                <a class="ad-btn is-primary sm" href="{{ route('admin.updates.index') }}">{{ $release['action'] }}</a>
            </x-slot:actions>
        </x-admin.callout>
    @else
        {{-- A newer AVDBCMS, from the kept GitHub check only; with nothing kept, the `updates` probe asks after the
             page appears and this section is filled in (modules/list-probes.ts). --}}
        <div data-ad-defer="home-release" @if ($release['checking']) data-ad-defer-probes="{{ route('admin.status.probe', ['probe' => 'updates']) }}" @endif>
            @if ($release['available'] !== null)
                <x-admin.callout class="ad-home-update" :title="__('AVDBCMS :version is available', ['version' => $release['available']['version']])" icon="cloud-down" role="status">
                    {{ __('Your site runs AVDBCMS :version.', ['version' => \App\Support\AppVersion::CURRENT]) }}
                    <x-slot:actions>
                        <a class="ad-btn is-primary sm" href="{{ route('admin.updates.index') }}">{{ __('See the update') }}</a>
                    </x-slot:actions>
                </x-admin.callout>
            @endif
        </div>
    @endif

    <x-admin.page-header
        page="home.overview"
        :title="config('site.maintenance_mode', false) ? __('Your site is in maintenance mode') : __('Your site is live')"
        :description="trim(__(':site had :views views this week.', ['site' => $siteName, 'views' => number_format($metrics['views']['week'])]).' '.($needCount > 0 ? trans_choice('1 thing needs you.|:count things need you.', $needCount, ['count' => $needCount]) : ''))"
    >
        <x-slot:actions>
            <a class="ad-btn" href="{{ locale_url(route('pages.home')) }}" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __('View site') }}</a>
            <a class="ad-btn is-primary" href="{{ route('admin.avdb-import.index') }}">{{ AdminTheme::icon('cloud-down') }}{{ __('Import videos') }}</a>
        </x-slot:actions>
    </x-admin.page-header>

    <div @class(['ad-home-top', 'is-single' => $steps === [] || $stepsHidden])>
        @if ($steps !== [] && ! $stepsHidden)
            <section class="ad-panel" aria-labelledby="ad-home-steps">
                <div class="ad-panel-h">
                    <div class="ad-home-grow">
                        <h2 id="ad-home-steps">{{ __('Get your site ready') }}</h2>
                        <p>{{ __(':done of :total done. This list hides itself when you finish.', ['done' => $doneSteps, 'total' => count($steps)]) }}</p>
                    </div>
                    <progress class="ad-home-progress" max="{{ count($steps) }}" value="{{ $doneSteps }}" aria-label="{{ __(':done of :total done', ['done' => $doneSteps, 'total' => count($steps)]) }}"></progress>
                </div>
                <x-admin.steps :steps="$steps" />
                <div class="ad-panel-f">
                    @if (\App\Support\AdminNav::page('money.setup') !== null && ($setupUrl = \App\Support\AdminNav::url(\App\Support\AdminNav::page('money.setup'))) !== null && $counts['plans'] === 0)
                        <span class="ad-small ad-muted">{{ __('Optional:') }}</span>
                        <a class="ad-small" href="{{ $setupUrl }}">{{ __('Sell premium memberships') }}</a>
                    @endif
                    <span class="ad-spacer"></span>
                    <form method="POST" action="{{ route('admin.home.dismiss') }}">
                        @csrf
                        <input type="hidden" name="notice" value="home-checklist">
                        <button type="submit" class="ad-linkbtn ad-small ad-muted">{{ __('Hide this list') }}</button>
                    </form>
                </div>
            </section>
        @endif

        <section class="ad-panel" aria-labelledby="ad-home-needs" data-ad-defer="home-needs"
                 @if ($waiting !== []) data-ad-defer-probes="{{ collect($waiting)->map(fn ($probe) => route('admin.status.probe', ['probe' => $probe]))->implode(' ') }}" @endif>
            <div class="ad-panel-h">
                <h2 id="ad-home-needs" class="ad-home-grow">{{ __('Needs you') }}</h2>
                @if ($needCount > 0)
                    <x-admin.badge>{{ $needCount }}</x-admin.badge>
                @endif
            </div>
            <ul class="ad-list">
                @foreach ($needs as $need)
                    <li>
                        <span class="ad-sym is-{{ $need['tone'] }}" aria-hidden="true">{{ AdminTheme::icon($need['icon']) }}</span>
                        <span class="ad-li-text"><b>{{ $need['title'] }}</b>@if ($need['text'])<span>{{ $need['text'] }}</span>@endif</span>
                        @if ($need['action'][1])
                            <a class="ad-btn sm" href="{{ $need['action'][1] }}">{{ $need['action'][0] }}</a>
                        @endif
                    </li>
                @endforeach
                @foreach ($found as $probe)
                    <li>
                        <span class="ad-sym is-{{ $probe['status'] === 'danger' ? 'danger' : 'warn' }}" aria-hidden="true">{{ AdminTheme::icon(['schedule' => 'clock', 'disk' => 'drive', 'health' => 'activity', 'provider' => 'cloud-down'][$probe['probe']] ?? 'alert') }}</span>
                        <span class="ad-li-text"><b>{{ $probe['label'] }}</b>@if ($probe['detail'])<span>{{ $probe['detail'] }}</span>@endif</span>
                        @if (! empty($probe['action']['url']))
                            <a class="ad-btn sm" href="{{ $probe['action']['url'] }}">{{ $probe['action']['label'] }}</a>
                        @endif
                    </li>
                @endforeach
                @if ($waiting !== [])
                    <li class="ad-home-checking" aria-live="polite">
                        <span class="ad-sym" aria-hidden="true">{{ AdminTheme::icon('refresh') }}</span>
                        <span class="ad-li-text"><span>{{ __('Checking the scheduled tasks, the disk and the server...') }}</span></span>
                    </li>
                @elseif ($needs === [] && $found === [])
                    <li>
                        <span class="ad-sym is-ok" aria-hidden="true">{{ AdminTheme::icon('check') }}</span>
                        <span class="ad-li-text"><b>{{ __('Nothing needs you right now') }}</b><span>{{ __('Reports, messages, imports and the server are all fine.') }}</span></span>
                    </li>
                @endif
            </ul>
            <div class="ad-panel-f ad-small ad-muted">
                {{ AdminTheme::icon('check-circle', 'sm') }}<span>{{ implode(' ', $statusLine) }}</span>
                @if ($steps !== [] && $stepsHidden)
                    <span class="ad-spacer"></span>
                    <form method="POST" action="{{ route('admin.home.dismiss') }}">
                        @csrf
                        <input type="hidden" name="notice" value="home-checklist">
                        <input type="hidden" name="show" value="1">
                        <button type="submit" class="ad-linkbtn">{{ __('Show the getting-started list') }}</button>
                    </form>
                @endif
            </div>
        </section>
    </div>

    <div class="ad-row ad-home-week">
        <h2 class="ad-h2 ad-home-grow">{{ __('This week') }}</h2>
        <span class="ad-small ad-faint">{{ __('Last 7 days, compared with the week before') }}</span>
    </div>
    <x-admin.metrics :class="$metrics['earnings']['enabled'] ? 'ad-home-metrics' : 'ad-home-metrics is-earnings-off'" :items="[
        ['label' => __('Views'), 'value' => number_format($metrics['views']['week']), 'delta' => $metric($metrics['views']), 'down' => $metrics['views']['week'] < $metrics['views']['before'], 'url' => route('admin.views.index'), 'spark' => $metrics['views']['points']],
        ['label' => __('New videos'), 'value' => number_format($metrics['videos']['week']), 'delta' => $metrics['videos']['today'] > 0 ? __('+:count today', ['count' => $metrics['videos']['today']]) : null, 'url' => route('admin.videos.index'), 'spark' => $metrics['videos']['points']],
        ['label' => __('New members'), 'value' => number_format($metrics['members']['week']), 'delta' => $metrics['members']['today'] > 0 ? __('+:count today', ['count' => $metrics['members']['today']]) : null, 'url' => route('admin.users.index'), 'spark' => $metrics['members']['points']],
        ['label' => __('Comments'), 'value' => number_format($metrics['comments']['week']), 'delta' => $metric($metrics['comments']), 'down' => $metrics['comments']['week'] < $metrics['comments']['before'], 'url' => route('admin.comments.index'), 'spark' => $metrics['comments']['points']],
        $metrics['earnings']['enabled']
            ? ['label' => __('Earnings'), 'value' => number_format($metrics['earnings']['week'], 2).' '.$metrics['earnings']['currency'], 'url' => route('admin.transactions.index')]
            : ['label' => __('Earnings'), 'value' => __('Premium is off'), 'delta' => __('Set up payments'), 'down' => true, 'url' => ($setup = \App\Support\AdminNav::page('money.setup')) !== null ? \App\Support\AdminNav::url($setup) : route('admin.plans.index')],
    ]" />

    <div class="ad-grid2 ad-home-bottom">
        <section class="ad-panel" aria-labelledby="ad-home-imports">
            <div class="ad-panel-h">
                <h2 id="ad-home-imports" class="ad-home-grow">{{ __('Recent imports') }}</h2>
                <a class="ad-small" href="{{ route('admin.avdb-import.index') }}">{{ __('All imports') }}</a>
            </div>
            @if ($imports === [])
                <p class="ad-panel-b ad-muted">{{ __('No imports yet. Videos you import from AVDBAPI or XVIDAPI are listed here.') }}</p>
            @else
                <ul class="ad-list">
                    @foreach ($imports as $run)
                        @php
                            $state = match ($run['status']) {
                                'completed' => ['ok', __('Finished')],
                                'failed' => ['danger', __('Failed')],
                                'cancelled' => ['off', __('Stopped')],
                                'queued', 'starting', 'running' => ['info', __('Running')],
                                default => ['off', \Illuminate\Support\Str::ucfirst((string) $run['status'])],
                            };
                            $when = \Illuminate\Support\Carbon::parse($run['finished_at'] ?? $run['created_at']);
                        @endphp
                        <li>
                            <span class="ad-li-text">
                                <b>{{ __(':provider import', ['provider' => $run['provider']]) }}</b>
                                <span>
                                    @if ($run['status'] === 'failed' && (int) $run['total_pages'] > 0)
                                        {{ __('Stopped at page :page of :pages', ['page' => (int) $run['completed_pages'] + 1, 'pages' => (int) $run['total_pages']]) }}
                                    @else
                                        {{ trans_choice('1 new video|:count new videos', (int) $run['imported'], ['count' => number_format((int) $run['imported'])]) }}, {{ trans_choice('1 failed|:count failed', (int) $run['failed'], ['count' => (int) $run['failed']]) }}
                                    @endif
                                </span>
                            </span>
                            <x-admin.status :type="$state[0]">{{ $state[1] }}</x-admin.status>
                            <time class="ad-small ad-faint ad-home-when" datetime="{{ $when->toIso8601String() }}">{{ $when->isToday() ? __('Today, :time', ['time' => $when->format('H:i')]) : ($when->isYesterday() ? __('Yesterday, :time', ['time' => $when->format('H:i')]) : $when->translatedFormat('j M')) }}</time>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="ad-panel" aria-labelledby="ad-home-comments" data-ad-list="home-comments" data-ad-failed="{{ __('That did not work. Reload the page and try again.') }}">
            <div class="ad-panel-h">
                <h2 id="ad-home-comments" class="ad-home-grow">{{ __('Latest comments') }}</h2>
                <a class="ad-small" href="{{ route('admin.comments.index') }}">{{ __('All comments') }}</a>
            </div>
            @if ($comments === [])
                <p class="ad-panel-b ad-muted">{{ __('No comments yet.') }}</p>
            @else
                <ul class="ad-list">
                    @foreach ($comments as $comment)
                        @php($author = $comment->user?->username ?? __('Deleted member'))
                        <li data-ad-row>
                            <span class="ad-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($author, 0, 2)) }}</span>
                            <span class="ad-li-text">
                                <b>{{ $author }}</b>
                                <span class="ad-home-quote">{{ \Illuminate\Support\Str::limit($comment->content, 140) }}</span>
                                <span>{{ __('on :video, :when', ['video' => \Illuminate\Support\Str::limit($comment->video?->title ?? __('a deleted video'), 60), 'when' => $comment->created_at?->diffForHumans()]) }}</span>
                            </span>
                            <x-admin.menu id="ad-home-comment-{{ $comment->id }}" :label="__('Actions for the comment of :name', ['name' => $author])">
                                @if ($comment->video && ! $comment->video->trashed())
                                    <a href="{{ $comment->video->route }}" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __('Open on site') }}</a>
                                @endif
                                <a href="{{ route('admin.comments.index', ['video' => $comment->video_id]) }}">{{ AdminTheme::icon('chat') }}{{ __('All comments on this video') }}</a>
                                <hr>
                                <form method="POST" action="{{ route('admin.comments.delete', $comment) }}" data-ad-row-action="delete"
                                      data-ad-confirm="{{ __('Delete this comment?') }}" data-ad-confirm-body="{{ __('Its replies and likes are deleted too.') }}" data-ad-confirm-label="{{ __('Delete comment') }}"
                                      data-ad-done="{{ __('Comment deleted') }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete comment') }}</button>
                                </form>
                            </x-admin.menu>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endsection
