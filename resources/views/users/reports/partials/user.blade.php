{{-- A reported channel in "Reports you sent" (member design 6.8; the member copy: the shared reports/user
     stays for the v1 admin). Prints the media cell and the body of the row. Variable: $report. --}}
@php($mkMember = $report->reportable)
<span class="mk-item__media mk-item__media--sm"><span class="mk-thumb mk-thumb--icon"><i class="fa-solid fa-user" aria-hidden="true"></i></span></span>
<div class="mk-item__body">
    @if($mkMember)
        <a class="mk-item__title mk-clamp-1" href="{{ $mkMember->route }}" dir="auto">{{ $mkMember->username }}</a>
        <span class="mk-muted" style="font-size:.8125rem">{{ __('Channel') }}</span>
    @else
        <span class="mk-item__title mk-clamp-1">{{ __('A channel that was deleted') }}</span>
    @endif
    @include('users.reports.partials.meta')
</div>
