{{-- The card expires soon (App\Notifications\Premium\CardExpired). The plain-text part (member design 6.21); the HTML part: mails.premium.card_expire. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::cardExpire($notifiable, (string) ($url ?? ''))])
