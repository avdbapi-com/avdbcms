{{--
    Tools > Background jobs > Failed, in the redesigned admin (the old admin keeps failed-jobs/index.blade.php): the jobs
    that failed with their name and the first line of the error (the whole error of one job opens above the list:
    ?show=<uuid>), bulk retry / delete, a row menu filled from one template (modules/list-rows.ts: retry, delete with
    Undo), retrying or deleting them all, the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Background jobs'))

@section('content')
    <x-admin.list-page>
    @php
        $jobName = function (?string $payload): string {
            preg_match('/"displayName":"((?:[^"\\\\]|\\\\.)*)"/', (string) $payload, $match);
            $class = isset($match[1]) ? (string) json_decode('"'.$match[1].'"') : '';

            return $class !== '' ? \Illuminate\Support\Str::headline(class_basename($class)) : __('Unknown job');
        };
    @endphp

    <x-admin.page-header page="tools.jobs"
        :description="trans_choice('1 job failed.|:count jobs failed.', $stats['total'], ['count' => number_format($stats['total'])]).($stats['today'] > 0 ? ' '.trans_choice('1 today.|:count today.', $stats['today'], ['count' => number_format($stats['today'])]) : '')">
        <x-slot:actions>
            @if ($stats['total'] > 0)
                <form method="POST" action="{{ route('admin.failed-jobs.retry-all') }}"
                      data-ad-confirm="{{ __('Retry every failed job?') }}" data-ad-confirm-body="{{ __('They go back to the queue and run again.') }}" data-ad-confirm-label="{{ __('Retry all') }}" data-ad-confirm-tone="primary">
                    @csrf
                    <button type="submit" class="ad-btn">{{ AdminTheme::icon('refresh') }}{{ __('Retry all...') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.failed-jobs.clear') }}"
                      data-ad-confirm="{{ __('Delete every failed job?') }}" data-ad-confirm-body="{{ __('They will not run again. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete all') }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="ad-btn is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete all...') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($shown)
        <section class="ad-panel ad-console-block" aria-labelledby="ad-job-error">
            <div class="ad-panel-h"><h2 id="ad-job-error">{{ __('Error of the job that failed :when', ['when' => \Illuminate\Support\Carbon::parse($shown->failed_at)->diffForHumans()]) }}</h2></div>
            <div class="ad-panel-b">
                {{-- Line breaks as character references: the page's whitespace trimming must not touch the trace. --}}
                <pre class="ad-message ad-code" tabindex="0" aria-labelledby="ad-job-error">{!! str_replace("\n", '&#10;', e((string) $shown->exception)) !!}</pre>
            </div>
            <div class="ad-panel-f"><a class="ad-link" href="{{ route('admin.failed-jobs.index', request()->except('show')) }}">{{ __('Close') }}</a></div>
        </section>
    @endif

    <form id="ad-failed-bulk" method="POST" action="{{ route('admin.bulk.failed-jobs') }}" hidden>@csrf</form>
    <form id="ad-failed-retry" method="POST" action="{{ route('admin.failed-jobs.index', [], false) }}" data-ad-row-action="change" data-ad-done="{{ __('Sent back to the queue') }}" hidden></form>
    <form id="ad-failed-delete" method="POST" action="{{ route('admin.failed-jobs.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Failed job deleted') }}"
          data-ad-confirm="{{ __('Delete this failed job?') }}" data-ad-confirm-body="{{ __('It will not run again. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-sel">{{ __('Select') }}</span><span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-failed-menu">
        <a href="{{ route('admin.failed-jobs.index', ['show' => '__ID__'], false) }}">{{ AdminTheme::icon('alert-circle') }}{{ __('Show the whole error') }}</a>
        <button type="submit" form="ad-failed-retry" formaction="{{ route('admin.failed-jobs.retry', ['uuid' => '__ID__'], false) }}">{{ AdminTheme::icon('refresh') }}{{ __('Retry') }}</button>
        <hr>
        <button type="submit" form="ad-failed-delete" formaction="{{ route('admin.failed-jobs.delete', ['uuid' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
    </template>

    <x-admin.table :paginator="$jobs" :label="__('Failed jobs')" list="failed-jobs" table-class="is-list" row-menu="ad-failed-menu">
        <x-slot:bulk>
            <button type="submit" class="ad-btn sm" form="ad-failed-bulk" name="action" value="retry">{{ AdminTheme::icon('refresh') }}{{ __('Retry') }}</button>
            <button type="submit" class="ad-btn is-danger sm" form="ad-failed-bulk" name="action" value="delete"
                    data-ad-confirm="{{ __('Delete the selected failed jobs?') }}" data-ad-confirm-body="{{ __('They will not run again. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete') }}">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
        </x-slot:bulk>

        <x-slot:head>
            <th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all aria-label="{{ __('Select all failed jobs on this page') }}"></th>
            <th scope="col">{{ __('Job') }}</th>
            <th scope="col" class="hide-tab">{{ __('Queue') }}</th>
            <th scope="col">{{ __('Failed') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="check-circle" :title="__('No failed jobs')">{{ __('Jobs that fail in the background are listed here so you can run them again.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($jobs as $job)
            @php
                $failed = \Illuminate\Support\Carbon::parse($job->failed_at);
                $error = \Illuminate\Support\Str::limit(trim((string) strtok((string) $job->exception_head, "\n")), 140);
            @endphp
            <tr>
                <td class="is-check"><input type="checkbox" name="ids[]" value="{{ $job->uuid }}" form="ad-failed-bulk" aria-labelledby="ad-l-sel ad-job-{{ $job->id }}"></td>
                <td class="c-main"><p><b id="ad-job-{{ $job->id }}">{{ $jobName($job->payload_head) }}</b><small>{{ $error }}</small></p></td>
                <td class="hide-tab">{{ $job->queue }} <small>{{ $job->connection }}</small></td>
                <td class="c-meta is-date" title="{{ $failed->translatedFormat('j M Y, H:i') }}">{{ $failed->diffForHumans(short: true) }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-job-{{ $job->id }}" data-id="{{ $job->uuid }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
