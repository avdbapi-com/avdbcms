@use('App\Filters\Forms\Admin\ReportFiltersForm')

@extends('layouts.admin')

@section('title', __('Reports'))

@section('content')
    @if($reports->total() || request()->all())
        <div x-data="{selected:[]}">
        <div class="d-flex justify-content-between align-items-center my-3">
            <h2><i class="fa-solid fa-flag me-2"></i>{{__('Reports')}}</h2>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <template x-if="selected.length > 0">
                    <div class="d-flex gap-2 align-items-center">
                        <span class="badge bg-primary fs-6" x-text="selected.length + ' {{ __("selected") }}'"></span>
                        <form method="POST" action="{{ route('admin.bulk.reports') }}">
                            @csrf
                            <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                            <input type="hidden" name="action" value="resolve">
                            <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-check me-1"></i>{{__('Resolve Selected')}}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.bulk.reports') }}">
                            @csrf
                            <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('{{ __('Delete selected reports?') }}')"><i class="fa-solid fa-trash me-1"></i>{{__('Delete Selected')}}</button>
                        </form>
                    </div>
                </template>
                <span class="badge bg-danger fs-6">{{ $reports->total() }} pending</span>
            </div>
        </div>
        <hr>
        {!! form(FormBuilder::create(ReportFiltersForm::class)) !!}
        <div class="table-responsive" tabindex="0">
            <table class="table table-bordered table-striped">
                <thead>
                <tr style="border-top: 3px solid #0D6EFD;">
                    <th scope="col">
                        <input type="checkbox" class="form-check-input" aria-label="{{ __('Select all') }}"
                            @change="if($event.target.checked) { selected = [...document.querySelectorAll('.bulk-check')].map(e => e.value) } else { selected = [] }"
                        >
                    </th>
                    <th style="min-width: 150px">{{__('Report By')}}</th>
                    <th style="width: 10%">{{__('Type')}}</th>
                    <th class="w-30">{{__('Content')}}</th>
                    <th class="w-30">{{__('Reason')}}</th>
                    <th>{{__('Status')}}</th>
                    <th style="min-width: 150px">{{__('Date')}}</th>
                    <th style="min-width: 180px">{{__('Actions')}}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($reports as $report)
                    <tr class="bg-light">
                        <td class="align-middle">
                            <input type="checkbox" class="form-check-input bulk-check" aria-label="{{ __('Select') }}" value="{{ $report->id }}" x-model="selected">
                        </td>
                        <td class="align-middle">
                            @if($report->user)
                                <a class="d-flex text-decoration-none align-items-center gap-2" href="{{$report->user->route}}">
                                    <img class="rounded" src="{{$report->user->avatar_url}}" alt="{{$report->user->username}} avatar" style="width: 50px;">
                                    <div>{{$report->user->username}}</div>
                                </a>
                            @else
                                <span class="text-muted"><i class="fa-solid fa-user-slash me-1"></i>{{__('Deleted user')}}</span>
                            @endif
                        </td>
                        <td class="align-middle">
                            <div class="d-flex align-items-center justify-content-center gap-2 badge bg-info">
                                <i class="fa-solid fa-{{$report->icon}}"></i>
                                {{$report->type}}
                            </div>
                        </td>
                        <td class="align-middle">
                            @include('reports.' .strtolower($report->type))
                        </td>
                        <td class="align-middle">
                            <div class="badge bg-danger">
                                {{$report->reason->value}}
                            </div>
                            <div class="mt-2">
                                <x-expand-item>
                                    {{$report->comment}}
                                </x-expand-item>
                            </div>
                        </td>
                        <td class="align-middle">
                            <div class="badge bg-{{$report->status->color()}}">
                                {{$report->status->name()}}
                            </div>
                        </td>
                        <td class="align-middle text-sm">
                            {{$report->created_at->diffForHumans()}}
                        </td>
                        <td class="align-middle">
                            <div class="d-flex gap-1 flex-wrap">
                                @if($report->reportable)
                                    {{-- Block is for a reported comment only (a member: Block member on their page; a video: Ban video). The
                                         reportable binding finds the model by its id, not by its route key (a member's is the profile slug). --}}
                                    @if($report->status->value !== 'blocked' && $report->type === 'Comment')
                                    <form method="POST" action="{{ route('admin.reports.block', [$report->type, $report->reportable_id]) }}" onsubmit='return confirm(@json(__('Block this content?')))'>
                                        @csrf
                                        <button class="btn btn-sm btn-danger"><i class="fa-solid fa-ban me-1"></i>{{__('Block')}}</button>
                                    </form>
                                    @endif
                                    @if($report->status->value !== 'cancelled')
                                    <form method="POST" action="{{ route('admin.reports.cancel', [$report->type, $report->reportable_id]) }}" onsubmit='return confirm(@json(__('Mark as resolved?')))'>
                                        @csrf
                                        <button class="btn btn-sm btn-success"><i class="fa-solid fa-check me-1"></i>{{__('Resolve')}}</button>
                                    </form>
                                    @endif
                                @else
                                    <span class="badge bg-secondary">{{__('Content deleted')}}</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center">
                            <i class="fa-solid fa-database fa-2x my-3"></i>
                            <p class="fw-bold">{{__('No matching reports')}}</p>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$reports" />
        </div>
    @else
        <div class="card shadow">
            <div class="card-body d-flex justify-content-center align-items-center">
                <div class="my-3 text-center">
                    <i class="fa-solid fa-eye-slash fa-2x"></i>
                    <h5 class="my-3">{{__('No reports are submitted.')}}</h5>
                </div>
            </div>
        </div>
    @endif
@endsection
