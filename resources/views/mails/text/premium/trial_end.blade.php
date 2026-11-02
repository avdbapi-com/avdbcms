{{-- The free trial ends soon (App\Notifications\Premium\TrialEnd). The plain-text part (member design 6.21); the HTML part: mails.premium.trial_end. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::trialEnd($notifiable)])
