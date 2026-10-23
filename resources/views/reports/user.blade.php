@if($report->reportable)
<a class="d-flex text-decoration-none align-items-center gap-2" href="{{$report->reportable->route}}">
    <img class="rounded" src="{{$report->reportable->avatar_url}}" alt="{{$report->reportable->username}} avatar" style="width: 50px;">
    <div>{{$report->reportable->username}}</div>
</a>
@else
<span class="text-muted"><i class="fa-solid fa-user-slash me-1"></i>{{__('User deleted')}}</span>
@endif
