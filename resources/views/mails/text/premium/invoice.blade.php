{{-- The Premium receipt, the PDF attached (App\Notifications\Premium\Invoice). The plain-text part (member design 6.21); the HTML part: mails.premium.invoice. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::invoice($notifiable, $transaction ?? null)])
