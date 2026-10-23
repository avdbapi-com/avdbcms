@session('error')
    <div class="alert alert-danger" role="alert">{!! $value !!}</div>
@endsession

@if ($errors->any())
    <div class="alert alert-danger" role="alert" tabindex="-1" id="auth-errors">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
