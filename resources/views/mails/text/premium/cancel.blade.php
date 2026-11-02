{{-- Premium was cancelled (App\Notifications\Premium\Cancel). The plain-text part (member design 6.21); the HTML part: mails.premium.cancel. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::cancel($notifiable)])
