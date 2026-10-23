@extends('layouts.default')

@section('title', __('Login'))

@section('content')
    <div class="avd-auth-page">
        <div class="row mx-0">
            <div class="avd-auth-panel border border-1 bg-light">
                <div class="row">
                    <div class="col-6 d-none d-xl-flex px-0 border-end border-gray-200 d-flex justify-content-center align-items-center bg-white">
                        <img class="img-fluid" src="{{asset('images/pages/login.png')}}" alt="{{__('Login')}}" width="640" height="640" decoding="async">
                    </div>
                    <div class="col-12 col-xl-6 py-4 py-sm-5 px-3 px-sm-5">
                        @if (session('success'))
                            <div class="alert alert-success fade show" role="alert">
                                <strong>{!! session('success') !!}</strong>
                            </div>
                        @endif
                        <h1 class="h3 mb-5 fw-normal text-center">{{__('Welcome Back !')}}</h1>
                        <form method="POST" action="{{ locale_url(route('login.perform')) }}">
                            @csrf
                            <x-auth-errors />
                            <div class="form-group form-floating mb-3 position-relative">
                                <input type="text" class="form-control" name="username" id="username" placeholder="{{__('Username')}}" required autocomplete="username" inputmode="text" autocapitalize="none" autocorrect="off" spellcheck="false" value="{{ old('username') }}">
                                <label for="username">{{__('Email or Username')}}</label>
                            </div>
                            <x-auth-password />
                            <div class="form-group d-flex justify-content-between align-items-center mb-3">
                                <div class="form-check">
                                    <input type="checkbox" id="remember" name="remember" class="form-check-input">
                                    <label class="form-check-label" for="remember">
                                        {{__('Remember me')}}
                                    </label>
                                </div>
                            </div>
                            <x-captcha action="login" />
                            <button class="w-100 btn btn-lg btn-primary" type="submit">{{__('Sign In')}}</button>
                            <div class="mt-4 mb-3 text-muted text-center">
                                @if(config('site.registration_enabled', true))
                                <div class="d-flex justify-content-center flex-wrap mb-3 gap-1 gap-sm-0">
                                    <span class="mr-2 text-body">{{__('Do not have an account ?')}}</span>
                                    <a href="{{ locale_url(route('registration')) }}" class="font-weight-bold text-decoration-none">
                                        <strong>{{__('Sign Up')}}</strong>
                                    </a>
                                </div>
                                @endif
                                <a href="{{ locale_url(route('password.forgot')) }}" class="text-muted text-decoration-none">{{__('Forgot your password ?')}}</a>
                            </div>
                        </form>
                        <x-auth-social mode="login" />
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
