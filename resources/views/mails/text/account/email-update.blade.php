{{-- Confirm a new e-mail address (App\Notifications\Account\VerifyUpdatedEmail). The plain-text part (member design 6.21); the HTML part: mails.account.email-update. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::emailUpdate($notifiable, (string) $url)])
