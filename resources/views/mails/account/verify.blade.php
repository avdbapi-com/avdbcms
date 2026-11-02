{{-- Sign-up: confirm the e-mail (Illuminate VerifyEmail, made in RouteServiceProvider::boot). The HTML part (member design 6.21); the plain-text part: mails.text.account.verify. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::verify($notifiable, (string) $url)])
