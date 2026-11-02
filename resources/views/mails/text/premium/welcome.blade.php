{{-- Premium is on (App\Notifications\Premium\Welcome). The plain-text part (member design 6.21); the HTML part: mails.premium.welcome. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::welcome($notifiable)])
