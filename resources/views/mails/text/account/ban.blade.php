{{-- The account was suspended (App\Notifications\Account\BanAccount). The plain-text part (member design 6.21); the HTML part: mails.account.ban. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::ban($notifiable)])
