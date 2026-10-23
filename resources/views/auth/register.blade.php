@extends('layouts.default')

@section('title', __('Register'))

@section('content')
    <div class="avd-auth-page">
        <div class="row mx-0">
            <div class="avd-auth-panel border border-1 bg-light">
                <div class="row">
                    <div class="col-6 d-none d-xl-flex px-0 border-end border-gray-200 d-flex justify-content-center align-items-center bg-white">
                        <img class="img-fluid" src="{{asset('images/pages/register.png')}}" alt="{{__('Register')}}" width="640" height="640" decoding="async">
                    </div>
                    <div class="col-12 col-xl-6 py-4 py-sm-5 px-3 px-sm-5">
                        <h1 class="h3 mb-5 fw-normal text-center">{{__('Create your Account !')}}</h1>
                        <form method="POST" action="{{ locale_url(route('registration.perform')) }}">
                            @csrf
                            <x-auth-errors />
                            <div class="form-group form-floating mb-3">
                                <input
                                    type="text"
                                    @class(['form-control', 'is-invalid' => $errors->has('username')])
                                    name="username"
                                    id="username"
                                    autocomplete="username" inputmode="text" autocapitalize="none" autocorrect="off" spellcheck="false"
                                    placeholder="{{__('Username')}}"
                                    required
                                    minlength="{{config('validation.user.username.min')}}"
                                    maxlength="{{config('validation.user.username.max')}}"
                                    value="{{ old('username') }}"
                                >
                                <label for="username">{{__('Username')}}</label>
                                @error('username')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                                @enderror
                            </div>
                            <div class="form-group form-floating mb-3">
                                <input
                                    type="email"
                                    @class(['form-control', 'is-invalid' => $errors->has('email')])
                                    name="email"
                                    id="email"
                                    autocomplete="email" inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false"
                                    placeholder="{{__('Email')}}"
                                    value="{{ old('email') }}"
                                    required
                                >
                                <label for="email">{{__('Email')}}</label>
                                @error('email')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                                @enderror
                            </div>
                            <x-auth-password autocomplete="new-password" :minlength="config('validation.user.password.min')" />
                            <x-auth-password name="password_confirmation" :label="__('Confirm password')" autocomplete="new-password" :minlength="config('validation.user.password.min')" />
                            <div class="form-check my-4">
                                <input @class(['form-check-input', 'is-invalid' => $errors->has('cgu')]) type="checkbox" id="cgu" name="cgu" value="1" required @checked(old('cgu'))>
                                <label class="form-check-label" for="cgu">
                                    {!! __('registration.terms', ['link' => locale_url(route('pages.terms'))]) !!}
                                </label>
                                @error('cgu')
                                <div class="invalid-feedback">
                                    {{ __('You must agree with Terms of service before creating a account.') }}
                                </div>
                                @enderror
                            </div>
                            <x-captcha action="register" />
                            <button class="w-100 btn btn-lg btn-primary" type="submit">{{ __('Register') }}</button>
                            <div class="mt-4 mb-3 text-muted text-center d-flex justify-content-center flex-wrap mb-3 gap-1 gap-sm-0">
                                <span class="mr-2 text-body">{{ __('Already have an account ?') }}</span>
                                <a href="{{ locale_url(route('login')) }}" class="font-weight-bold text-decoration-none">
                                    <strong>{{ __('Sign In') }}</strong>
                                </a>
                            </div>
                        </form>
                        <x-auth-social mode="register" />
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
