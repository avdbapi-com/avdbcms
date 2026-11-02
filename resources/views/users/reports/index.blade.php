{{-- "Reports you sent" (member design 6.8): the reported item (video thumb, comment icon or channel icon),
     the status (Pending / Reviewed: removed / Reviewed: no action), the reason and the date. A pending report can be
     cancelled: "Cancel this report?" with Keep the report / Cancel report. The security rule (S06) stays in the controller:
     own and pending only, any other report answers 404. --}}
@extends('layouts.user')

@section('title', __('Reports you sent'))

@section('content')
<div class="avd-mk mk-page">
    <x-mk.head icon="flag" :kicker="__('Activity')" :title="__('Reports you sent')" :desc="__('The team reviews every report. You can cancel a report while it is pending.')" />
    <x-mk.flash refusal />

    @if($reports->total() === 0)
        <section class="mk-panel">
            <x-mk.empty icon="flag" :title="__('No reports')" :text="__('When you report a video, a comment or a channel, you can follow it here.')" />
        </section>
    @else
        <section class="mk-panel" aria-label="{{ __('Reports') }}">
            <ul class="mk-rows">
                @foreach($reports as $report)
                    @php($kind = match (class_basename((string) $report->reportable_type)) { 'Video' => 'video', 'Comment' => 'comment', default => 'user' })
                    <li class="mk-item mk-item--media">
                        @include('users.reports.partials.'.$kind, ['report' => $report])
                        <div class="mk-item__aside">
                            @if($report->is_pending)
                                <button type="button" class="mk-btn mk-btn--quiet mk-btn--sm" data-bs-toggle="modal" data-bs-target="#mk-cancel-report" data-action="{{ route('user.reports.cancel', $report) }}"><span>{{ __('Cancel report') }}</span></button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            {{ $reports->onEachSide(1)->links('vendor.pagination.mk') }}
        </section>

        <div class="modal fade" id="mk-cancel-report" tabindex="-1" aria-labelledby="mk-cancel-report-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
                <div class="modal-content avd-mk">
                    <div class="mk-sheet__head">
                        <h2 class="mk-sheet__title" id="mk-cancel-report-title">{{ __('Cancel this report?') }}</h2>
                        <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    <div class="mk-sheet__body"><p class="mk-sheet__lead">{{ __('The team will not review it. You can report the item again later.') }}</p></div>
                    <div class="mk-sheet__foot">
                        <button type="button" class="mk-btn mk-btn--quiet" data-bs-dismiss="modal" data-mk-autofocus>{{ __('Keep the report') }}</button>
                        <button type="submit" class="mk-btn mk-btn--danger" form="mk-cancel-report-form"><span>{{ __('Cancel report') }}</span></button>
                    </div>
                    <form id="mk-cancel-report-form" method="POST" action="" data-mk-action-from hidden>@csrf</form>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
    @include('users.partials.studio-scripts', ['studioScriptsParts' => ['confirm']])
@endpush
