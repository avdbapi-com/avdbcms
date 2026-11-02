{{-- Confirm a new e-mail address (App\Notifications\Account\VerifyUpdatedEmail). The HTML part (member design 6.21); the plain-text part: mails.text.account.email-update. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::emailUpdate($notifiable, (string) $url)])
