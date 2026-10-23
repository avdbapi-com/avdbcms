@extends('layouts.admin')
@section('title', __('Email Test'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-envelope me-2"></i>{{__('Email Configuration & Test')}}</h2>
</div>
<hr>

<div class="row">
    {{-- SMTP Config --}}
    <div class="col-md-5 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-info text-white"><h6 class="mb-0"><i class="fa-solid fa-gear me-1"></i>{{__('Current Mail Configuration')}}</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    @foreach($config as $key => $val)
                    <tr>
                        <td class="fw-bold ps-3">{{ $key }}</td>
                        <td>
                            @if(in_array($key, ['Password']))
                                <code>••••••••</code>
                            @else
                                <code>{{ $val ?: '(not set)' }}</code>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </table>
            </div>
        </div>

        <div class="card shadow-sm mt-3">
            <div class="card-header"><h6 class="mb-0"><i class="fa-solid fa-info-circle me-1"></i>{{__('Tips')}}</h6></div>
            <div class="card-body">
                <ul class="small mb-0">
                    <li>{{ __('Configure SMTP settings in the Settings Email/SMTP tab') }}</li>
                    <li>{{ __('Common ports: 465 (SSL), 587 (TLS), 25 (unencrypted)') }}</li>
                    <li>{!! __('Use :driver driver in development to write emails to log file', ['driver' => '<code>log</code>']) !!}</li>
                    <li>{!! __('Use :mailgun, :ses, or :postmark for production', ['mailgun' => '<code>mailgun</code>', 'ses' => '<code>ses</code>', 'postmark' => '<code>postmark</code>']) !!}</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Send Test --}}
    <div class="col-md-7 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><h6 class="mb-0"><i class="fa-solid fa-paper-plane me-1"></i>{{__('Send Test Email')}}</h6></div>
            <div class="card-body">
                @if(config('mail.default') === 'log')
                    <div class="alert alert-warning small">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>
                        {!! __('The mailer is currently :driver: nothing is delivered to inboxes. Every email (password resets, verification, this test) is written to :file instead. Configure SMTP in :link to send real email.', ['driver' => '<code>log</code>', 'file' => '<code>storage/logs/mail.log</code>', 'link' => '<a href="' . route('admin.settings.index', ['tab' => 'email']) . '" class="alert-link">' . __('Settings → Email') . '</a>']) !!}
                    </div>
                @endif
                @if($errors->has('email'))
                    <div class="alert alert-danger"><i class="fa-solid fa-exclamation-triangle me-1"></i>{{ $errors->first('email') }}</div>
                @endif
                <form action="{{ route('admin.email-test.send') }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="ad-email-test-to">{{__('Recipient Email')}}</label>
                        <input id="ad-email-test-to" type="email" name="to" class="form-control" value="{{ auth()->user()->email }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="ad-email-test-subject">{{__('Subject')}}</label>
                        <input id="ad-email-test-subject" type="text" name="subject" class="form-control" value="{{ __('Test Email from :site', ['site' => config('app.name')]) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="ad-email-test-body">{{__('Body')}}</label>
                        <textarea id="ad-email-test-body" name="body" class="form-control" rows="4" required>{{ __('This is a test email sent from the admin panel at :time. If you received this, email configuration is working correctly.', ['time' => now()->format('Y-m-d H:i:s')]) }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane me-1"></i>{{__('Send Test Email')}}</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
