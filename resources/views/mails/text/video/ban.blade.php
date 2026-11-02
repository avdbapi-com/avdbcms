{{-- A video was suspended (App\Notifications\Video\VideoBanned). The plain-text part (member design 6.21); the HTML part: mails.video.ban. The words: App\Notifications\Support\MemberMail. --}}
@include('mails.layouts.text', ['mail' => \App\Notifications\Support\MemberMail::videoBan($notifiable, $video ?? null)])
