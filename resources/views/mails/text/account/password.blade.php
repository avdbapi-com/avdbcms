{{-- The password was changed (App\Notifications\Account\PasswordUpdate). The plain-text part (member design 6.21); the HTML part: mails.account.password. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::password($notifiable)])
