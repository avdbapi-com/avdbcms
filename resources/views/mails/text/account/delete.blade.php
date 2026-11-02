{{-- The account was deleted (App\Notifications\Account\DeleteAccount). The plain-text part (member design 6.21); the HTML part: mails.account.delete. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::delete($notifiable)])
