{{-- Premium was cancelled (App\Notifications\Premium\Cancel). The HTML part (member design 6.21); the plain-text part: mails.text.premium.cancel. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::cancel($notifiable)])
