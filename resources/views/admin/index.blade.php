@extends('layouts.admin')

@section('title', __('Dashboard'))

@section('content')
    <div x-data="{reportable: {}}">
        {{-- A new version is uploaded: its database changes wait for one click (the new admin's Home has the same). --}}
        @if (($pendingMigrations ?? 0) > 0)
            <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="status">
                <div class="flex-grow-1">
                    <strong>{{ __('Finish the update') }}</strong>
                    {{ trans_choice('A new version is uploaded. 1 database change is waiting - your site keeps working while you do this.|A new version is uploaded. :count database changes are waiting - your site keeps working while you do this.', $pendingMigrations, ['count' => $pendingMigrations]) }}
                </div>
                <form method="POST" action="{{ route('admin.update.finish') }}" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-warning">{{ __('Finish update') }}</button>
                </form>
            </div>
        @elseif (! empty($availableRelease))
            {{-- A newer AVDBCMS (the kept check of Tools > Updates, which installs it; the old admin has no page of its own for it). --}}
            <div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status">
                <strong class="flex-grow-1">{{ __('AVDBCMS :version is available', ['version' => $availableRelease]) }}</strong>
                <a href="{{ route('admin.updates.index') }}" class="btn btn-sm btn-primary">{{ __('See the update') }}</a>
            </div>
        @endif

        {{-- Quick Actions --}}
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0"><i class="fa-solid fa-chart-line me-2"></i>{{__('Dashboard')}}</h3>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.settings.index') }}" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-gear me-1"></i>{{__('Settings')}}</a>
                <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-info"><i class="fa-solid fa-users me-1"></i>{{__('Users')}}</a>
                <a href="{{ route('admin.videos.index') }}" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-video me-1"></i>{{__('Videos')}}</a>
                <a href="{{ route('admin.categories.index') }}" class="btn btn-sm btn-outline-warning"><i class="fa-solid fa-folder me-1"></i>{{__('Categories')}}</a>
            </div>
        </div>

        {{-- Stats Row 1 - Core --}}
        <div class="row mb-3">
            @foreach([
                [__('Videos'), $videos_count, 'fa-video', 'primary', route('admin.videos.index')],
                [__('Users'), $users_count, 'fa-users', 'success', route('admin.users.index')],
                [__('Comments'), $comments_count, 'fa-comments', 'info', route('admin.comments.index')],
                [__('Reports'), $reports_count, 'fa-flag', 'danger', route('admin.reports.index')],
                [__('Total Views'), $totalViews, 'fa-eye', 'warning', '#'],
                [__('Subscriptions'), $subscriptions, 'fa-crown', 'warning', route('admin.subscriptions.index')],
                [__('Categories'), $categoriesCount, 'fa-folder', 'secondary', route('admin.categories.index')],
                [__('Playlists'), $playlistsCount, 'fa-list', 'dark', route('admin.playlists.index')],
            ] as $s)
            <div class="col-md-3 col-6 mb-3">
                <a href="{{ $s[4] }}" class="text-decoration-none">
                    <div class="card shadow-sm border-{{ $s[3] }} h-100">
                        <div class="card-body text-center py-3">
                            <i class="fa-solid {{ $s[2] }} fa-lg text-{{ $s[3] }} mb-1"></i>
                            <div class="fw-bold fs-4">{{ number_format($s[1]) }}</div>
                            <small class="text-muted">{{ $s[0] }}</small>
                        </div>
                    </div>
                </a>
            </div>
            @endforeach
        </div>

        {{-- Today's Activity --}}
        <div class="row mb-3">
            @foreach([
                [__("Today's Users"), $todayUsers, 'fa-user-plus', 'primary'],
                [__("Today's Videos"), $todayVideos, 'fa-film', 'success'],
                [__("Today's Comments"), $todayComments, 'fa-message', 'info'],
                [__('Unread Contacts'), $unreadContacts, 'fa-envelope', 'danger'],
                [__('Failed Jobs'), $failedJobsCount, 'fa-triangle-exclamation', 'warning'],
                [__('Active Subs'), $activeSubscriptions, 'fa-star', 'warning'],
                [__('IP Bans'), $ipBansCount, 'fa-shield-halved', 'secondary'],
                [__('Searches'), $searchesCount, 'fa-search', 'dark'],
            ] as $s)
            <div class="col-md-3 col-6 mb-3">
                <div class="card shadow-sm border-0 bg-{{ $s[3] }} bg-opacity-10 h-100">
                    <div class="card-body text-center py-2">
                        <i class="fa-solid {{ $s[2] }} text-{{ $s[3] }}"></i>
                        <div class="fw-bold fs-5">{{ number_format($s[1]) }}</div>
                        <small class="text-muted">{{ $s[0] }}</small>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- System Health Bar --}}
        <div class="row mb-3">
            @foreach([
                ['PHP', PHP_VERSION, 'primary'],
                ['Laravel', app()->version(), 'danger'],
                [__('DB Size'), $dbSize, 'info'],
                [__('Disk Free'), $diskFree, 'success'],
                [__('Storage'), $storageUsed, 'warning'],
                [__('Env'), config('app.env'), config('app.env') === 'production' ? 'success' : 'warning'],
                [__('Debug'), config('app.debug') ? __('ON') : __('OFF'), config('app.debug') ? 'danger' : 'success'],
                [__('Cache'), config('cache.default'), 'secondary'],
            ] as $s)
            <div class="col-md-3 col-6 mb-2">
                <div class="d-flex justify-content-between align-items-center border rounded px-3 py-1">
                    <small class="text-muted">{{ $s[0] }}</small>
                    <span class="badge bg-{{ $s[2] }}">{{ $s[1] }}</span>
                </div>
            </div>
            @endforeach
        </div>

        {{-- Remote fleet status requires a live health source; keep this dashboard local-only. --}}

        <div class="row mb-4">
            {{-- Recent Users --}}
            <div class="col-md-6 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="fa-solid fa-user-plus me-1"></i>{{__('Recent Users')}}</h6>
                        <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-primary">{{__('View All')}}</a>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <tbody>
                            @foreach($recentUsers as $u)
                            <tr>
                                <td><img src="{{ $u->avatar_url }}" class="rounded" style="width:30px;height:30px" alt=""></td>
                                <td><a href="{{ route('admin.users.show', $u) }}" class="text-decoration-none">{{ $u->username }}</a></td>
                                <td><small class="text-muted">{{ $u->email }}</small></td>
                                <td><small>{{ $u->created_at->diffForHumans() }}</small></td>
                                <td>
                                    @if($u->is_admin) <span class="badge bg-danger">{{__('Admin')}}</span>
                                    @else <span class="badge bg-secondary">{{__('User')}}</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            {{-- Recent Videos --}}
            <div class="col-md-6 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="fa-solid fa-film me-1"></i>{{__('Recent Videos')}}</h6>
                        <a href="{{ route('admin.videos.index') }}" class="btn btn-sm btn-outline-primary">{{__('View All')}}</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" tabindex="0">
                            <table class="table table-sm mb-0">
                                <tbody>
                                @foreach($recentVideos as $v)
                                <tr>
                                    <td><a href="{{ route('admin.videos.edit', $v) }}" class="text-decoration-none">{{ Str::limit($v->title, 40) }}</a></td>
                                    <td><small class="text-muted">{{ $v->user?->username ?? __('N/A') }}</small></td>
                                    <td><span class="badge bg-info">{{ trans_choice('views', $v->views) }}</span></td>
                                    <td><small>{{ $v->created_at->diffForHumans() }}</small></td>
                                </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Chart --}}
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
        <div class="card shadow border-primary h-100 mb-4">
            <div class="card-body">
                <h4 class="card-title text-center text-primary mb-3">{{__('Premium Gains')}}</h4>
                <div class="d-block" style="height: 547px">
                    <x-chartjs-component :chart="$chart" />
                </div>
            </div>
        </div>

        {{-- Reports Table --}}
        <div class="row mb-4">
            <div class="col">
                <div class="card">
                    <div class="card-header d-flex align-items-center gap-3 p-3">
                        <i class="fa-solid fa-flag"></i>
                        <h5 class="card-title mb-0">
                            {{ __('Most Reported contents') }}
                        </h5>
                    </div>
                    <div class="card-body">
                        @if($reports->count())
                            <div class="table-responsive" tabindex="0">
                                <table class="table">
                                    <thead>
                                    <tr>
                                        <th scope="col">{{__('Type')}}</th>
                                        <th scope="col" class="w-50" style="min-width: 400px">{{__('Content')}}</th>
                                        <th scope="col" style="min-width: 150px">{{__('First Report')}}</th>
                                        <th scope="col">{{ __('Count') }}</th>
                                        <th scope="col" style="min-width: 305px">{{__('Actions')}}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($reports as $report)
                                        <tr>
                                            <td class="align-middle">
                                                <div class="d-flex align-items-center justify-content-center gap-2 badge bg-info">
                                                    <i class="fa-solid fa-{{$report->icon}}"></i>
                                                    {{$report->type}}
                                                </div>
                                            </td>
                                            <td class="align-middle ps-3">
                                                @if($report->reportable)
                                                    @if($report->type == 'Video')
                                                        <div class="d-flex gap-3 align-items-start">
                                                            <a href="{{$report->reportable->route}}">
                                                                <img src="{{$report->reportable->thumbnail_url}}" alt="" style="width: 120px;height: 68px">
                                                            </a>
                                                            <div class="d-flex flex-column gap-1">
                                                                <small>{{Str::limit($report->reportable->title, 100), '...'}}</small>
                                                                <small class="text-muted">{{Str::limit($report->reportable->description, 200), '...'}}</small>
                                                                @if($report->reportable->user)
                                                                    <a class="text-primary text-sm text-decoration-none" href="{{$report->reportable->user->route}}">
                                                                        {{$report->reportable->user->username}}
                                                                    </a>
                                                                @else
                                                                    <span class="text-muted text-sm"><i class="fa-solid fa-user-slash me-1"></i>{{__('Deleted user')}}</span>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @elseif($report->type == 'Comment')
                                                        @if($report->reportable->user)
                                                            <a class="text-primary text-sm text-decoration-none" href="{{$report->reportable->user->route}}">
                                                                {{$report->reportable->user->username}}
                                                            </a>
                                                        @else
                                                            <span class="text-muted text-sm"><i class="fa-solid fa-user-slash me-1"></i>{{__('Deleted user')}}</span>
                                                        @endif
                                                        <x-expand-item max="400">
                                                            {{$report->reportable->content}}
                                                        </x-expand-item>
                                                    @elseif($report->type == 'User')
                                                        <a class="d-flex text-decoration-none align-items-center gap-2" href="{{$report->reportable->route}}">
                                                            <img class="rounded" src="{{$report->reportable->avatar_url}}" alt="{{$report->reportable->username}} avatar" style="width: 50px;">
                                                            <div>{{$report->reportable->username}}</div>
                                                        </a>
                                                    @endif
                                                @else
                                                    <span class="text-muted"><i class="fa-solid fa-trash me-1"></i>{{__('Content deleted')}}</span>
                                                @endif
                                            </td>
                                            <td class="align-middle text-sm">
                                                <div data-bs-toggle="tooltip" data-bs-title="{{$report->first_report_at->translatedFormat('d F Y - H:i')}}">
                                                    {{$report->first_report_at->diffForHumans()}}
                                                </div>
                                            </td>
                                            <td class="align-middle">
                                                <div class="badge bg-secondary">
                                                    {{trans_choice('reports', $report->reports_count)}}
                                                </div>
                                            </td>
                                            <td class="align-middle text-sm">
                                                @if($report->reportable)
                                                    <div class="d-flex gap-2 align-items-center">
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-primary"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#reports"
                                                        >
                                                            <i class="fa-solid fa-flag"></i>&nbsp;
                                                            {{ __('See reports') }}
                                                        </button>
                                                        {{-- Block is for a reported comment only (a member: Block member on their page; a video: Ban video). --}}
                                                        @if($report->type === 'Comment')
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-danger"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#block_report"
                                                            data-title="{{$report->reportable->title ?? ''}}"
                                                            data-poster="{{$report->reportable->thumbnail_url ?? ''}}"
                                                            data-route="{{route('admin.reports.block', ['type' => strtolower($report->type), 'reportable' => $report->reportable_id])}}"
                                                            @click="reportable = $el.dataset;"
                                                        >
                                                            <i class="fa-solid fa-ban"></i>&nbsp;
                                                            {{ __('Block') }}
                                                        </button>
                                                        @endif
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-secondary"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#cancel_report"
                                                            data-title="{{$report->reportable->title ?? ''}}"
                                                            data-poster="{{$report->reportable->thumbnail_url ?? ''}}"
                                                            data-route="{{route('admin.reports.cancel', ['type' => strtolower($report->type), 'reportable' => $report->reportable_id])}}"
                                                            @click="reportable = $el.dataset;"
                                                        >
                                                            <i class="fa-solid fa-xmark"></i>&nbsp;
                                                            {{ __('Cancel') }}
                                                        </button>
                                                    </div>
                                                @else
                                                    <span class="badge bg-secondary">{{__('Content deleted')}}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr class="bg-light">
                                            <td colspan="7" class="text-center">
                                                <i class="fa-solid fa-bell-slash fa-2x my-3"></i>
                                                <p class="fw-bold">{{__('No matching reports for this period')}}</p>
                                            </td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="text-center my-3">
                                <i class="fa-solid fa-eye-slash fa-2x"></i>
                                <h5 class="my-3">{{__('No reports yet')}}</h5>
                            </div>
                        @endif
                    </div>
                    <div class="card-footer">
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="{{route('admin.reports.index')}}" class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-eye"></i>&nbsp;
                                {{ __('See all reports') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @include('admin.reports.modals.reports')
        @include('admin.reports.modals.block')
        @include('admin.reports.modals.cancel')
    </div>
@endsection
