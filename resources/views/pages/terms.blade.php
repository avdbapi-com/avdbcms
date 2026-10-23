@extends('layouts.default')

@php
    $seoService = app(\App\Services\SeoService::class);
@endphp

@section('title', $seoService->renderTemplate('terms_meta_title_template', [], __('Terms of Service'), 180))
@section('description', $seoService->renderTemplate('terms_meta_description_template', [], __('Read the terms of service, account rules, privacy notes, and data policies for using :site.', ['site' => config('app.name')]), 500))

@section('content')
    <div class="avd-secondary-page avd-terms-page">
        <div class="avd-secondary-hero">
            <div class="avd-secondary-kicker"><i class="fa-solid fa-file-contract"></i>{{ __('Legal') }}</div>
            <h1 class="avd-secondary-title">{{ __('Terms of Service') }}</h1>
            <p class="avd-secondary-subtitle">{{ __('Read the account rules, privacy notes, and data policies for using :site.', ['site' => config('app.name')]) }}</p>
        </div>
        <div class="avd-secondary-panel avd-terms-body">
            @if(config('site.terms_content'))
                {!! config('site.terms_content') !!}
            @else
                <p>{{ __(':site agrees to furnish services to the Subscriber subject to the following Terms of Service. Use of :site service constitutes acceptance and agreement to :site Terms of Service.', ['site' => config('app.name')]) }}</p>
                <p>{{ __(':site reserves the right to modify the Terms of Service without notice.', ['site' => config('app.name')]) }}</p>
                <h2 class="mt-4 mb-3 h3">{{__('Use of specific personal data')}}</h2>
                <p>{{ __('When you leave a comment on :site, the data entered in the form, but also your IP address and the user agent of your browser are collected to help us detect unwanted comments.', ['site' => config('app.name')]) }}</p>
                <p>{{__('After validation of your comment, your profile photo will be publicly visible next to your comment.')}}</p>
                <h2 class="mt-4 mb-3 h3">{{__('Avatars')}}</h2>
                <p>{{ __('If you are a registered user and upload images to the website, we advise you to avoid uploading images containing EXIF coordinate data. Visitors to your website can download and extract location data from these images.') }}</p>
                <h2 class="mt-4 mb-3 h3">{{__('Cookies')}}</h2>
                <p>{{ __('If you have an account and you connect to the site, a temporary cookie will be created in order to persist your connection state. This cookie will be automatically destroyed when you log out of the site.') }}</p>
                <h2 class="mt-4 mb-3 h3">{{__('Statistics and audience measurements')}}</h2>
                <p>
                    {{ __('In order to analyze site traffic (number of visits, most viewed pages...) we do not use a third-party service but a self-hosted service based on') }}
                    <a href="https://umami.is/">{{__('Umami')}}</a>.
                    {{ __('The data is anonymized and does not identify the individual behavior of a user but rather a general trend (number of visits to the site and number of visits per page only).') }}
                </p>
                <h2 class="mt-4 mb-3 h3">{{__('Storage times for your data')}}</h2>
                <p>{{ __('If you leave a comment, the comment and its metadata are retained indefinitely. This automatically recognizes and approves subsequent comments instead of leaving them in the moderation queue.') }}</p>
                <p>{{ __('For users who register on the site, we also store the personal data indicated in their profile. All users can view, modify or delete their personal information at any time. Only the site manager can also see and modify this information.') }}</p>
                <h2 class="mt-4 mb-3 h3">{{__('The rights you have over your data')}}</h2>
                <p>{{ __('If you have an account or if you have left comments on the site, you can request the deletion of personal data concerning you. This does not take into account data stored for administrative, legal or security reasons.') }}</p>
                <p>{{ __('If you have an account and wish to delete your information you can do so automatically from your account by clicking on the deleted button.') }}</p>
                <h2 class="mt-4 mb-3 h3">{{__('Transmission of your personal data')}}</h2>
                <p>{{__('Your data is not shared with a third party.')}}</p>
                <h2 class="mt-4 mb-3 h3">{{__('Protection of your data')}}</h2>
                <p>{{ __('So that the personal data that we collect is neither lost nor disclosed by unauthorized third parties, we have implemented the necessary measures (firewall, database not accessible remotely, etc.) in proportion to the sensitivity stored data.') }}</p>
            @endif
        </div>
    </div>
@endsection
