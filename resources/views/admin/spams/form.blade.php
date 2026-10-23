{{--
    Community > Spam filter in the redesigned admin (the old admin keeps spams/index.blade.php): the words and email
    addresses that mark a comment or message as spam, one per line, saved with the save bar (Ctrl+S) to the same
    address with the same fields as before.
--}}
@extends('layouts.admin')

@section('title', __('Spam filter'))

@section('content')
    <x-admin.page-header page="community.spam" :description="__('Comments and messages that contain one of these words or come from one of these email addresses are treated as spam.')" />

    <form id="ad-spam-form" method="POST" action="{{ route('admin.spams.update') }}" data-ad-form>
        @csrf
        @method('PUT')
        <div class="ad-grid2">
            <section class="ad-panel" aria-labelledby="ad-spam-words">
                <div class="ad-panel-h"><h2 id="ad-spam-words">{{ __('Blocked words') }}</h2></div>
                <div class="ad-panel-b">
                    <x-admin.field name="words" :label="__('Words')" :help="__('One word or phrase per line.')">
                        <textarea class="ad-textarea ad-code" id="words" name="words" rows="16">{{ old('words', $words) }}</textarea>
                    </x-admin.field>
                </div>
            </section>
            <section class="ad-panel" aria-labelledby="ad-spam-emails">
                <div class="ad-panel-h"><h2 id="ad-spam-emails">{{ __('Blocked email addresses') }}</h2></div>
                <div class="ad-panel-b">
                    <x-admin.field name="emails" :label="__('Email addresses')" :help="__('One address per line.')">
                        <textarea class="ad-textarea ad-code" id="emails" name="emails" rows="16">{{ old('emails', $emails) }}</textarea>
                    </x-admin.field>
                </div>
            </section>
        </div>
        <x-admin.savebar form="ad-spam-form" />
    </form>
@endsection
