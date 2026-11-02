{{-- Premium is on (App\Notifications\Premium\Welcome). The HTML part (member design 6.21); the plain-text part: mails.text.premium.welcome. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.html', ['mail' => \App\Notifications\Support\MemberMail::welcome($notifiable)])
