@if($report->reportable)
<div class="d-flex gap-3 align-items-start mb-3">
    @if($report->reportable->video)
        <a href="{{$report->reportable->video->route}}">
            <img src="{{$report->reportable->video->thumbnail_url}}" alt="" style="width: 120px;height: 68px">
        </a>
        <div class="d-flex flex-column gap-1">
            <small>{{Str::limit($report->reportable->video->title, 100), '...'}}</small>
            @if($report->reportable->video->user)
                <a class="text-sm text-decoration-none" href="{{$report->reportable->video->user->route}}">{{$report->reportable->video->user->username}}</a>
            @else
                <span class="text-muted text-sm"><i class="fa-solid fa-user-slash me-1"></i>{{__('Deleted user')}}</span>
            @endif
        </div>
    @else
        <span class="text-muted"><i class="fa-solid fa-video-slash me-1"></i>{{__('Video deleted')}}</span>
    @endif
</div>
@if($report->reportable->user)
    <a class="text-sm text-decoration-none badge rounded-pill text-bg-secondary" href="{{$report->reportable->user->route}}">{{$report->reportable->user->username}} : </a>
@else
    <span class="badge rounded-pill text-bg-secondary"><i class="fa-solid fa-user-slash me-1"></i>{{__('Deleted user')}} : </span>
@endif
<x-expand-item>
    {{$report->reportable->content}}
</x-expand-item>
@else
<span class="text-muted"><i class="fa-solid fa-trash me-1"></i>{{__('Comment deleted')}}</span>
@endif
