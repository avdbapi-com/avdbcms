{{-- The account was suspended (App\Notifications\Account\BanAccount). The HTML part (member design 6.21); the plain-text part: mails.text.account.ban. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::ban($notifiable)])
