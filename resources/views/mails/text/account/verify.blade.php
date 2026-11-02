{{-- Sign-up: confirm the e-mail (Illuminate VerifyEmail, made in RouteServiceProvider::boot). The plain-text part (member design 6.21); the HTML part: mails.account.verify. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::verify($notifiable, (string) $url)])
