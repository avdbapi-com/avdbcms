@extends('layouts.default')

@section('title', __('Password reset'))

@section('content')
    <div class="avd-auth-page">
        <div class="row mx-0">
            <div class="avd-auth-panel border border-1 bg-light">
                <div class="row">
                    <div class="col-6 d-none d-xl-flex px-0 border-end border-gray-200 d-flex justify-content-center align-items-center bg-white">
                        <img class="img-fluid" src="{{asset('images/pages/reset.jpg')}}" alt="{{__('Reset')}}" width="640" height="640" decoding="async">
                    </div>
                    <div class="col-12 col-xl-6 py-4 py-sm-5 px-3 px-sm-5">
                        <h1 class="h3 mb-5 fw-normal text-center">{{__('Password reset')}}</h1>
                        <form method="POST" action="{{ locale_url(route('password.update')) }}">
                            @csrf
                            <x-auth-errors />
                            <div class="form-group form-floating mb-3">
                                <input
                                    type="email"
                                    value="{{request()->get('email')}}"
                                    @class(['form-control', 'is-invalid' => $errors->has('email')])
                                    name="email"
                                    id="email"
                                    autocomplete="username"
                                    inputmode="email"
                                    autocapitalize="none"
                                    autocorrect="off"
                                    spellcheck="false"
                                    placeholder="{{__('Email')}}"
                                    required
                                    readonly
                                >
                                <label for="email">{{__('Email')}}</label>
                                @error('email')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                                @enderror
                            </div>
                            <x-auth-password :label="__('New Password')" autocomplete="new-password" :minlength="config('validation.user.password.min')" />
                            <x-auth-password name="password_confirmation" :label="__('Confirm New Password')" autocomplete="new-password" :minlength="config('validation.user.password.min')" />
                            <input type="hidden" name="token" value="{{$token}}">
                            <button class="w-100 btn btn-lg btn-primary" type="submit">{{__('Reset Password')}}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
