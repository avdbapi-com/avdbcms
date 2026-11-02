{{-- "Forgot password": the reset link (Illuminate ResetPassword, made in RouteServiceProvider::boot). The plain-text part (member design 6.21); the HTML part: mails.account.forgot. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::forgot($notifiable, (string) $url)])
