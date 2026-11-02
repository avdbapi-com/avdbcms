{{-- The plain-text part of every member e-mail (member design 6.21): the same words as the HTML part, as text: no
     Markdown "#", no HTML tags or entities ("©" written as such), every address complete and on its own line. Member text
     (a user name, a visitor's message) is printed as it was typed: a text part is never read as HTML or Markdown.
     $mail: the array of App\Notifications\Support\MemberMail. --}}
@php
    $mailSite = \App\Notifications\Support\MemberMail::site();
    $mailButton = is_array($mail['button'] ?? null) && ($mail['button']['url'] ?? '') !== '' ? $mail['button'] : null;
    $mailPanel = is_array($mail['panel'] ?? null) ? $mail['panel'] : null;
    $mailLines = [$mailSite, '', (string) ($mail['heading'] ?? ''), ''];
    if (($mail['greeting'] ?? null) !== null && $mail['greeting'] !== '') {
        $mailLines[] = (string) $mail['greeting'];
        $mailLines[] = '';
    }
    foreach ((array) ($mail['paragraphs'] ?? []) as $mailParagraph) {
        $mailLines[] = (string) $mailParagraph;
        $mailLines[] = '';
    }
    if ($mailPanel) {
        if (($mailPanel['title'] ?? '') !== '') {
            $mailLines[] = (string) $mailPanel['title'];
        }
        foreach ((array) ($mailPanel['lines'] ?? []) as $mailLine) {
            $mailLines[] = (string) $mailLine;
        }
        if (($mailPanel['text'] ?? '') !== '') {
            $mailLines[] = '----';
            $mailLines[] = str_replace(["\r\n", "\r"], "\n", (string) $mailPanel['text']);
            $mailLines[] = '----';
        }
        $mailLines[] = '';
    }
    // A label before its address: the language puts its own colon (French "libellé :", Chinese "：", judge R3-064).
    if ($mailButton) {
        $mailLines[] = __(':label:', ['label' => (string) $mailButton['label']]);
        $mailLines[] = (string) $mailButton['url'];
        $mailLines[] = '';
    }
    if (($mail['note'] ?? null) !== null && $mail['note'] !== '') {
        $mailLines[] = (string) $mail['note'];
        $mailLines[] = '';
    }
    if (is_array($mail['help'] ?? null) && ($mail['help']['url'] ?? '') !== '') {
        $mailLines[] = (string) $mail['help']['label'];
        $mailLines[] = (string) $mail['help']['url'];
        $mailLines[] = '';
    }
    $mailLines[] = '--';
    if (($mail['audience'] ?? 'member') === 'owner') {
        $mailLines[] = __('You get this e-mail because you run :site.', ['site' => $mailSite]);
    } else {
        $mailLines[] = __('You get this e-mail because of your account on :site.', ['site' => $mailSite]);
        if (($mail['manage'] ?? true) !== false) {
            $mailLines[] = __(':label:', ['label' => __('Manage e-mails')]);
            $mailLines[] = \App\Notifications\Support\MemberMail::link('user.edit', ['section' => 'notifications']);
        }
    }
    $mailLines[] = '© '.now()->year.' '.$mailSite;
    $mailText = preg_replace("/\n{3,}/", "\n\n", implode("\n", $mailLines));
@endphp
{!! $mailText !!}
