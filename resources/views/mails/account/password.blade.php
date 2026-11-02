{{-- The password was changed (App\Notifications\Account\PasswordUpdate). The HTML part (member design 6.21); the plain-text part: mails.text.account.password. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::password($notifiable)])
