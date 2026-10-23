@component('mail::message')

# {{ __('Hello :username.', ['username' => $notifiable->username]) }}

{!! __('You are receiving this email because one of your video was banned for violating our :terms.', ['terms' => '<a target="_blank" class="text-danger fw-bold" href="' . url('/terms') . '">'.__('Terms of Service').'</a>']) !!}

@component('mail::panel')
    <img src="{{$video->thumbnail_url}}" alt="">
    <strong style="margin-top: 10px;display: block">{{$video->title}}</strong>
    <small style="margin-top: 10px;display: block">{{trans_choice('views', $video->views)}} &bull; {{$video->created_at->translatedFormat('d F Y')}}</small>
@endcomponent

{{ __('If you want contest this decision, please contact our support.') }}

@component('mail::button', ['url' => route('contact.show')])
    {{ __('Contact Support') }}
@endcomponent

{{ __('Regards, :site', ['site' => config('app.name')]) }}

@endcomponent

