@extends('layouts.admin')
@section('title', __('Artisan Commands'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-solid fa-terminal me-2"></i>{{__('Artisan Command Runner')}}</{{ $titleTag }}>
</div>

<div class="row">
    <div class="col-md-5">
        <div class="card shadow">
            <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-play me-1"></i>{{__('Available Commands')}}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.artisan.run') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="ad-artisan-command">{{__('Select Command')}}</label>
                        <select name="command" id="ad-artisan-command" class="form-select" required>
                            <option value="">{{__('-- Choose command --')}}</option>
                            @foreach($commands as $cmd => $desc)
                            <option value="{{ $cmd }}" {{ $lastCommand === $cmd ? 'selected' : '' }}>{{ $cmd }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <table class="table table-sm table-bordered mb-0" style="font-size:0.85em">
                            @foreach($commands as $cmd => $desc)
                            <tr>
                                <td><code>{{ $cmd }}</code></td>
                                <td class="text-muted">{{ $desc }}</td>
                            </tr>
                            @endforeach
                        </table>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" onclick='return confirm(@json(__('Run this artisan command?')))'>
                        <i class="fa-solid fa-play me-1"></i>{{__('Execute Command')}}
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card shadow">
            <div class="card-header d-flex justify-content-between">
                <h5 class="mb-0"><i class="fa-solid fa-display me-1"></i>{{__('Output')}}</h5>
                @if($lastCommand)<span class="badge bg-info">{{ $lastCommand }}</span>@endif
            </div>
            <div class="card-body p-0">
                <pre class="mb-0 p-3" style="min-height:400px; max-height:70vh; overflow:auto; font-size:13px; line-height:1.5; background:#1e1e2e; color:#a6e3a1; white-space:pre-wrap;">{{ $output ?? '> Waiting for command...' }}</pre>
            </div>
        </div>
    </div>
</div>
@endsection

