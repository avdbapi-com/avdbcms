@extends('layouts.default')

@section('title', __('Contact'))
@section('description', __('Contact :site support for account, video, billing, or website help.', ['site' => config('app.name')]))

@php($contactIllustrationEnabled = config('site.contact_illustration_enabled', true))

@section('content')
    <div class="avd-secondary-page avd-contact-page">
        <div class="avd-secondary-hero">
            <div class="avd-secondary-kicker"><i class="fa-solid fa-headset"></i>{{ __('Support') }}</div>
            <h1 class="avd-secondary-title">{{ __('Contact Us') }}</h1>
            <p class="avd-secondary-subtitle">{{ __('Send account, video, billing, or website questions to the support team.') }}</p>
        </div>
        <div class="avd-secondary-panel avd-contact-panel">
            <div class="row g-0">
                @if($contactIllustrationEnabled)
                    <div class="col-12 col-xl-6 d-none d-xl-flex flex-column px-0 border-end border-gray-200 justify-content-center align-items-center avd-contact-visual">
                        <img class="img-fluid" src="{{asset('images/pages/contact.png')}}" alt="{{__('Contact Us')}}">
                    </div>
                @endif
                    <div @class(['col-12 py-5 px-3 px-sm-5', 'col-xl-6' => $contactIllustrationEnabled])>
                        @if (session('error'))
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <strong>{!! session('error') !!}</strong>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{__('Close')}}"></button>
                            </div>
                        @endif
                        @if (session('success'))
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <strong>{!! session('success') !!}</strong>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{__('Close')}}"></button>
                            </div>
                        @endif
                        <form method="POST" action="{{route('contact.contact')}}">
                            @csrf
                            <div class="row">
                                <div class="col-12 col-sm-6 mb-3">
                                    <label for="name" class="form-label">{{ __('Your name') }}</label>
                                    <input
                                        type="text"
                                        minlength="{{config('validation.contact.name.min')}}"
                                        maxlength="{{config('validation.contact.name.max')}}"
                                        @class(['form-control', 'is-invalid' => $errors->has('name')])
                                        name="name"
                                        id="name"
                                        required
                                        autofocus
                                        value="{{ old('name') }}"
                                    >
                                    @error('name')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                    @enderror
                                </div>
                                <div class="col-12 col-sm-6 mb-3">
                                    <label for="email" class="form-label">{{ __('Your email') }}</label>
                                    <input type="email" @class(['form-control', 'is-invalid' => $errors->has('email')]) name="email" id="email" required value="{{ old('email') }}">
                                    @error('email')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                    @enderror
                                </div>
                            </div>
                            <div class="mb-3" x-data="{ count: 0 }" x-init="count = $refs.message.value.length">
                                <label for="message" class="form-label">{{ __('Your message') }}</label>
                                <textarea
                                    @class(['form-control', 'is-invalid' => $errors->has('message')])
                                    id="message"
                                    rows="15"
                                    name="message"
                                    maxlength="{{config('validation.contact.message.max')}}"
                                    x-ref="message"
                                    @keyup="count = $refs.message.value.length"
                                >{{old('message')}}</textarea>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="form-text">
                                        <span x-text="count"></span> / <span>{{config('validation.contact.message.max')}}</span>
                                    </div>
                                    @error('message')
                                    <div @class(['invalid-feedback w-75 text-end', 'd-block' => $errors->has('message')])>
                                        {{ $message }}
                                    </div>
                                    @enderror
                                </div>
                            </div>
                            <x-captcha action="contact" />
                            <button class="w-100 btn btn btn-primary" type="submit">{{ __('Send') }}</button>
                        </form>
                    </div>
            </div>
        </div>
    </div>
@endsection
