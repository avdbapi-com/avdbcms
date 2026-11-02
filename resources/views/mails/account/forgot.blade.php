{{-- "Forgot password": the reset link (Illuminate ResetPassword, made in RouteServiceProvider::boot). The HTML part (member design 6.21); the plain-text part: mails.text.account.forgot. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::forgot($notifiable, (string) $url)])
