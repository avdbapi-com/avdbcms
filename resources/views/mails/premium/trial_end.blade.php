{{-- The free trial ends soon (App\Notifications\Premium\TrialEnd). The HTML part (member design 6.21); the plain-text part: mails.text.premium.trial_end. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::trialEnd($notifiable)])
