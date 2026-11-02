<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminSearchIndex;
use App\Support\AdminText;
use App\Support\SiteDate;
use App\Support\Studio\ZoneLabel;
use App\Support\WatchHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 1.2.3 round 3, lane WA's second pass (the independent check's findings and the orchestrator's in-scope item). Each test
 * fails on the code of int-C4 (RC2 + the pre-fix pass).
 *  - R3-048 (rest, RV-WA-01): no hard-coded "label: value" join is left in the non-protected views and scripts: the
 *    studio playlist editor's Remove buttons, the header search's "Categories" label, the admin's search, Appearance
 *    calendar and every admin page that printed "{{ __('X') }}:" write a label and its value in the reader's punctuation
 *    (":label: :value" on the member side, AdminText::labelled() in the admin: "：" in Chinese, " :" in French);
 *  - RV-WA-02 (R3-062's class): the watch page's own date and a planned video's line follow the site's date rules;
 *  - RV-WA-03, RV-WA-04 (R3-016): Bootstrap's own Offcanvas never takes the bell sheet over, and the closed menu's button
 *    opens the menu on its keys with the focus on an item (the keys themselves are proven in three browsers by the lane);
 *  - RV-WA-05 (R1-069): one video a day still fits History's English content budget: a row's menu is printed once, a
 *    day's question only where it is not the dialog's template with the day's name;
 *  - RV-WA-06: an empty History prints its list empty (the :empty rule hides it, as after "Clear all").
 * Pass 3 (the independent check's VF1-WA-01): the joins rule also catches a colon right after a closing tag (and the
 * other forms named on it); the new admin's "Developer mode is off" line and the old admin's cache purge result join
 * their parts in the admin's punctuation.
 * Pass 4 (the independent check's VF2-WA-01): a label printed as its own element with the language's colon is followed
 * by the language's gap (App\Support\LabelGap: nothing after the Chinese "：") and its value, never by a space of the
 * markup: the watch page's playlist line and the 31 admin label lines of passes 1-2; the joins rule catches that space.
 * Pass 5 (the independent check's VF3-WA-01): a value element that isolates its direction (dir="ltr") right after a
 * label's colon and gap gets a <wbr> before it: Chromium finds no line break between the Chinese "：" and such an
 * element, so on a phone it broke the label word instead ("Stripe webhook 地" / "址：http://…"); the joins rule catches
 * a missing <wbr> there.
 */
class WatchAndLibraryRoundThreeSecondPassTest extends TestCase
{
    use RefreshDatabase;

    /** Files the joins rule does not cover: the owner's hands-off files, R3-064's mail line (lane TL), the English-only installer. */
    private const NOT_COVERED = [
        'resources/js/components/Videos/Player.tsx',
        'resources/js/components/Videos/VideoUpload.tsx',
        'resources/js/components/Videos/Ad.tsx',
        'resources/views/users/videos/modals/upload.blade.php',
        'resources/views/users/videos/partials/create-status-poll.blade.php',
        'resources/views/layouts/embed.blade.php',
        'resources/views/mails/layouts/text.blade.php',
        'resources/views/install/index.blade.php',
    ];

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        app()->setLocale('en');

        parent::tearDown();
    }

    private function source(string $relative): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(base_path($relative)));
    }

    /** A page in another language: public/index.php takes "/xx" off the address and hands it to the Language middleware. */
    private function in(string $locale, string $path, ?User $member = null): string
    {
        config(['site.default_locale' => 'en']);
        $_SERVER['DETECTED_LOCALE'] = $locale;
        try {
            $request = $member ? $this->actingAs($member) : $this;

            return $request->get($path)->assertOk()->getContent();
        } finally {
            unset($_SERVER['DETECTED_LOCALE']);
            app()->setLocale('en');
        }
    }

    private function pageRoot(string $html): string
    {
        $this->assertSame(1, preg_match('/<(div)\b[^>]*\bclass="[^"]*\bmk-page\b[^"]*"/', $html, $match, PREG_OFFSET_CAPTURE));
        $start = (int) $match[0][1];
        $depth = 0;
        preg_match_all('/<(\/?)div\b[^>]*>/', $html, $tags, PREG_OFFSET_CAPTURE, $start);
        foreach ($tags[0] as $n => [$tag, $at]) {
            $depth += $tags[1][$n][0] === '/' ? -1 : 1;
            if ($depth === 0) {
                return substr($html, $start, $at + strlen($tag) - $start);
            }
        }
        $this->fail('the page root has no end');
    }

    /**
     * A page root with its words taken out (MemberChannelLibraryFixesTest::markupOnly, the ruling's measure), and the
     * direction a creator's text gets on a page of the other direction (App\Support\TextDirection, lane CS: " dir=ltr" on a
     * Latin title of an Arabic page): the ruling's addition of 30 Oct counts it as part of the language's difference.
     */
    private function markupOnly(string $root): string
    {
        $markup = (string) preg_replace('/>[^<]+</u', '><', $root);
        $markup = (string) preg_replace('/\sdir=(?:"(?:ltr|rtl|auto)"|ltr|rtl|auto)(?=[\s>\/])/u', '', $markup);

        return (string) preg_replace('/\s(aria-label|title|alt|placeholder|data-label[a-z-]*|data-message-[a-z-]+|data-day-question|data-remove-day-[a-z]+)="[^"]*"/u', ' $1=""', $markup);
    }

    // ------------------------------------------------------------------------------------------- R3-048, RV-WA-01

    /**
     * No colon is written in code between a label and its value: every such join goes through the language's pattern
     * (":label: :value" / ":label:" on the member side, AdminText::labelled() or AdminText::punctuation()['label'] in the
     * admin). Pass 3 (independent check VF1-WA-01) widened the rule: a colon right after a closing tag
     * ("<b>{{ $label }}</b>: text", "'</strong>: ' + message", "</b>: {value}" in JSX), after any echo (not only a
     * translation or a variable) and after "&nbsp;", a string that ends with an expression and its colon
     * ("`${label}: ` + value"), {T.x}: / {texts.x}: in JSX, a list joined with ": " (join / implode), sprintf("%s: %s"),
     * "{$a}: {$b}" and a stylesheet's content: ": "; the views' own scripts (resources/views/**.js) and the stylesheets
     * are read too. A time, a ratio, host:port, uid:gid or a key ("1:05", "{{ $h }}:{{ $m }}", 'feed:' + id) has no space
     * after its colon and is no join. English-only texts that carry their own English colon (the English-only installer;
     * the old admin's leftovers listed in the lane's NOTE.md) are untranslated words, not a join of translated parts.
     * Pass 4 (independent check VF2-WA-01): nor a space of the markup after a label's colon. A label printed with the
     * language's colon as its own element or as text (":label:", rtrim(AdminText::labelled($label, '')),
     * AdminText::punctuation()['label']) is followed by the language's own gap (App\Support\LabelGap: a space, nothing
     * after the Chinese "：") and then its value, or its value element at once (AdminText::labelled($label, '') holds the
     * gap), never by a space, a tab or "&nbsp;" of the markup on its line ('gap', 'gap-js'), nor by a line break and then
     * text or an echo on the next line ($nextLine, Blade): both are drawn as a space ("下一个： 标题", "注意： 直接编辑…").
     * A line break and then an element or a directive is a block or a flex item below the label (contacts/show's <p>, the
     * Video details' flex rows, the Reports pill above its excerpt), where no space is drawn; JSX draws no line break.
     * Pass 5 (independent check VF3-WA-01): nor a value element that isolates its direction right after a label's colon
     * and gap with no <wbr> between them ('gap-isolate'): an element with a dir attribute, <bdi>, <bdo> or lane CS's
     * TextDirection::attr(). Chromium finds no line break between the Chinese "：" and such an element, so a value that
     * does not fit on the label's line broke the label word itself on a phone ("Stripe webhook 地" / "址：http://…");
     * the zero-width <wbr> gives the break back with no space and no character.
     */
    public function test_no_hard_coded_label_join_is_left_in_the_views_and_scripts_outside_the_hands_off_files(): void
    {
        $rules = [
            // Blade: an echo right before the colon, then a space, a tag, an entity, a quote or the line's end:
            // {{ __('X') }}: value, {{ $label }}:</strong>, {{ $name }} : </a>, {{ __('X') }}&nbsp;: , '{{ __('X') }}: ' + v
            // ("{{ $h }}:{{ $m }}", a time or host:port, is no join)
            'echo' => '/(?:\}\}|!!\})(?:\s|&nbsp;|&#160;)?:(?=[\s<&"\'`]|$)/',
            // a closing tag right before the colon: <b>{{ $label }}</b>: text, '...</strong>: ' + message, </b>: {value} in
            // JSX (a JSX ternary writes " : " after a tag, never ">:"); and a string that ends with a tag and " : "
            'tag' => '/<\/[A-Za-z][\w.-]*>(?:&nbsp;|&#160;)?:(?=[\s<&"\'`{]|$)|([\'"`])(?:(?!\1).)*<\/[A-Za-z][\w.-]*>\s:\s*\1/',
            // PHP: __('X') . ': ' . $y, ': ' . $y (the operator after a space, a word or a bracket: "'...' : ''" is a ternary)
            'php' => "/(?<=[\\s\\w)\\]])\\.\\s*(['\"])\\s?:\\s+\\1|(['\"])\\s?:\\s+\\2\\s*\\./",
            // scripts: + ': ', ': ' +, `${a}: ${b}`, `${label}: ` + value, {t('X')}: / {T.x}: / {texts.x}: in JSX
            // (the operator after a space, a word or a bracket: "'+' : ''" is a ternary)
            'js' => "/(?<=[\\s\\w)\\]])\\+\\s*(['\"`])\\s?:\\s+\\1|(['\"`])\\s?:\\s+\\2\\s*\\+|\\$\\{[^}]+\\}\\s?:\\s+(?:\\$\\{|`\\s*\\+)|\\{\\s*(?:t|__|i18n\\.t)\\([^}]*\\)\\s*\\}\\s?:(?=[\\s<{])|\\{\\s*(?:T|texts?)\\.\\w+\\s*\\}\\s?:(?=[\\s<{])/",
            // a list joined with a colon, sprintf("%s: %s"), "{$a}: {$b}", a stylesheet's content: ": "
            'other' => "/(?:\\.join|implode)\\(\\s*(['\"`])\\s?:\\s*\\1|%(?:\\d\\\$)?s\\s?:\\s+%(?:\\d\\\$)?[sd]|\\{\\\$[^}]+\\}\\s?:\\s+\\{\\\$|content\\s*:\\s*(['\"])\\s?:\\s*\\2/",
            // pass 4 (VF2-WA-01): a space of the markup after a label's colon. A label printed with the language's colon
            // ({{ __(':label:', ...) }}, {{ rtrim(AdminText::labelled($label, '')) }} or without rtrim,
            // {{ AdminText::punctuation()['label'] }}, @lang(':label:', ...)) or the gap after it ({{ LabelGap::admin() }} /
            // ::member()), then closing tags and a space, a tab or &nbsp;, then the value on the same line:
            // <span class="fw-bold">{{ __(':label:', ...) }}</span> {{ $title }} showed "下一个： 标题" in Chinese
            'gap' => <<<'RE'
                ~(?:(?:\{\{|\{!!)\s*(?:(?:rtrim\(\s*)?(?:\\?App\\Support\\)?AdminText::labelled\((?:(?!\}\}|!!\}).)*?,\s*(?:''|"")\s*\)\s*\)?|(?:__|trans|trans_choice)\(\s*(?:':label:'|":label:")(?:(?!\}\}|!!\}).)*?|(?:\\?App\\Support\\)?AdminText::punctuation\([^)]*\)\[\s*(?:'label'|"label")\s*\]|(?:\\?App\\Support\\)?LabelGap::\w+\([^)]*\))\s*(?:\}\}|!!\})|@lang\(\s*(?:':label:'|":label:")(?:[^()]|\([^()]*\))*\))(?:[ \t]|&nbsp;|&#160;|</[A-Za-z][\w.-]*>)*?(?:[ \t]|&nbsp;|&#160;)(?:[ \t]|&nbsp;|&#160;|</[A-Za-z][\w.-]*>)*(?=[^\s<]|<(?!/))~
                RE,
            // the same in JSX ({t(':label:', ...)} then closing tags and a space or {' '} before the value; a line break
            // between JSX elements is no space) and in a script (a ':label:' text joined to its value with + ' ')
            'gap-js' => <<<'RE'
                ~\{\s*(?:t|__|__r|i18n\.t)\(\s*(?:':label:'|":label:"|`:label:`)(?:[^{}]|\{[^{}]*\})*\}(?:</[A-Za-z][\w.-]*>)*(?:[ \t]+|\{\s*(?:' '|" "|` `)\s*\})(?:[ \t]|</[A-Za-z][\w.-]*>)*(?=[^\s<]|<(?!/))|\b(?:t|__|__r|i18n\.t)\(\s*(?:':label:'|":label:"|`:label:`)[^;]*?\)\s*\+\s*(?:'(?:\s|&nbsp;)+'|"(?:\s|&nbsp;)+"|`(?:\s|&nbsp;)+`)~
                RE,
            // pass 5 (VF3-WA-01): the same label (Blade or JSX) or its gap, then only tags (no text, no <wbr>), then a
            // value element that isolates its direction: a dir attribute (not data-dir), <bdi>, <bdo>, or lane CS's
            // {!! TextDirection::attr() !!} in the tag. Chromium finds no line break between "：" and that element:
            // {{ AdminText::labelled($label, '') }}<code dir="ltr">{{ $url }}</code> broke "Stripe webhook 地" / "址：http://…"
            'gap-isolate' => <<<'RE'
                ~(?:(?:\{\{|\{!!)\s*(?:(?:rtrim\(\s*)?(?:\\?App\\Support\\)?AdminText::labelled\((?:(?!\}\}|!!\}).)*?,\s*(?:''|"")\s*\)\s*\)?|(?:__|trans|trans_choice)\(\s*(?:':label:'|":label:")(?:(?!\}\}|!!\}).)*?|(?:\\?App\\Support\\)?AdminText::punctuation\([^)]*\)\[\s*(?:'label'|"label")\s*\]|(?:\\?App\\Support\\)?LabelGap::\w+\([^)]*\))\s*(?:\}\}|!!\})|@lang\(\s*(?:':label:'|":label:")(?:[^()]|\([^()]*\))*\)|\{\s*(?:t|__|__r|i18n\.t)\(\s*(?:':label:'|":label:"|`:label:`)(?:[^{}]|\{[^{}]*\})*\})(?:</[A-Za-z][\w.-]*>|<(?!wbr\b|bd[io]\b)[A-Za-z][\w.-]*(?:\s(?:[^>{]|\{\{(?:(?!\}\}).)*\}\}|\{!!(?:(?!!!\}).)*!!\})*)?>)*?<(?:bd[io]\b|[A-Za-z][\w.-]*\s(?:[^>{]|\{\{(?:(?!\}\}).)*\}\}|\{!!(?:(?!!!\}).)*!!\})*?(?:(?<=\s)dir\s*=|\{!!\s*\\?(?:App\\Support\\)?TextDirection::))~
                RE,
        ];
        // pass 4 (VF2-WA-01), Blade: the same label at the end of its line and the value as text or an echo on the next line
        // (a line break is drawn as a space there: "<strong>...</strong>\n    {{ $mode }}." showed "存储方式： Authenticated."). A
        // line break and then an element or a directive: a block or a flex item below the label, where no space is drawn.
        $nextLine = <<<'RE'
            ~(?:(?:\{\{|\{!!)\s*(?:(?:rtrim\(\s*)?(?:\\?App\\Support\\)?AdminText::labelled\((?:(?!\}\}|!!\}).)*?,\s*(?:''|"")\s*\)\s*\)?|(?:__|trans|trans_choice)\(\s*(?:':label:'|":label:")(?:(?!\}\}|!!\}).)*?|(?:\\?App\\Support\\)?AdminText::punctuation\([^)]*\)\[\s*(?:'label'|"label")\s*\]|(?:\\?App\\Support\\)?LabelGap::\w+\([^)]*\))\s*(?:\}\}|!!\})|@lang\(\s*(?:':label:'|":label:")(?:[^()]|\([^()]*\))*\))(?:[ \t]|&nbsp;|&#160;|</[A-Za-z][\w.-]*>)*\n(?:[ \t]*\n)*[ \t]*(?=\{(?!\{--)|[^\s<@{])~
            RE;

        // the rule itself catches every form it is meant for (and none of the shapes that are no join)
        $catches = [
            "<p><b>{{ \$setting['label'] }}</b>: {{ __('This setting appears in Developer mode (account menu).') }}</p>",
            "alertBox.innerHTML = '<strong>' + data.timestamp + '</strong>: ' + data.message;",
            "<span>{{ __('Duration') }}: {{ \$time }}</span>", "<li>{{ __('Size') }}&nbsp;: {{ \$size }}</li>", "{!! \$label !!}: x",
            "aria-label={T.remove + ': ' + title}", "<span>{t('Categories')}:</span>", "<dt>{T.size}: {size}</dt>",
            "toast(`\${label}: \${value}`);", "toast(`\${label}: ` + value);", "@section('title', __('Edit') . ': ' . \$name)",
            "parts.join(': ')", "sprintf('%s: %s', \$a, \$b)", "\"{\$label}: {\$value}\"", ".x::after { content: ': '; }",
            "<b>{t('Total')}</b>: {total}", "html = '<b>' + name + '</b> : ' + value;",
            // pass 4 (VF2-WA-01): a space of the markup after a label's colon
            "<div class=\"text-truncate\"><span class=\"fw-bold\">{{ __(':label:', ['label' => __('Next')]) }}</span> {{\$effectiveNextVideo->title}}</div>",
            "<strong>{{ rtrim(\\App\\Support\\AdminText::labelled(__('Caution'), '')) }}</strong> {{__('Editing this file directly can break your application.')}}",
            "{{ rtrim(\\App\\Support\\AdminText::labelled(__('Queue driver'), '')) }} <code>{{ \$queueDriver }}</code>",
            "<strong>{{ \\App\\Support\\AdminText::labelled(__('Caution'), '') }}</strong> {{ \$text }}",
            "<b>{{ \$label }}{{ \\App\\Support\\AdminText::punctuation()['label'] }}</b>&nbsp;{{ \$value }}",
            "<span>{{ __(':label:', ['label' => __('Size')]) }} </span>{{ \$size }}",
            "<strong>{{ rtrim(\\App\\Support\\AdminText::labelled(__('Name'), '')) }}</strong>{{ \\App\\Support\\LabelGap::admin() }} {{ \$name }}",
            "@lang(':label:', ['label' => 'Size']) {{ \$size }}",
            "<b>{t(':label:', {nsSeparator: false}).replace(':label', () => name)}</b> {value}",
            "<b>{t(':label:').replace(':label', () => name)}</b>{' '}{value}",
            "el.textContent = __r(':label:', {label: name}) + ' ' + value;",
            // pass 5 (VF3-WA-01): a value element that isolates its direction right after the label, no <wbr> before it
            "<div class=\"ad-help\" data-webhook-url>{{ \\App\\Support\\AdminText::labelled(\$key === 'STRIPE_WEBHOOK_SECRET' ? __('Stripe webhook address') : __('PayPal webhook address'), '') }}<code dir=\"ltr\">{{ \$url }}</code></div>",
            "<div><strong>{{ rtrim(\\App\\Support\\AdminText::labelled(__('Remote lookup'), '')) }}</strong>{{ \\App\\Support\\LabelGap::admin() }}<span dir=\"ltr\">{{ \$n }}x / {{ \$s }}s</span></div>",
            "<span class=\"fw-bold\">{{ __(':label:', ['label' => __('Next')]) }}</span>{{ \\App\\Support\\LabelGap::member() }}<bdi>{{ \$title }}</bdi>",
            "{{ \\App\\Support\\AdminText::labelled(__('UUID'), '') }}<code><span dir=\"ltr\">{{ \$uuid }}</span></code>",
            "<b>{{ \$label }}{{ \\App\\Support\\AdminText::punctuation()['label'] }}</b><a href=\"{{ \$url }}\"{!! \\App\\Support\\TextDirection::attr(\$title) !!}>{{ \$title }}</a>",
            "<b>{t(':label:', {nsSeparator: false}).replace(':label', () => name)}</b><bdi>{value}</bdi>",
        ];
        $noJoins = [
            "var time = m + ':' + pad(s);", "{{ \$h }}:{{ \$m }}", "var sign = delta > 0 ? '+' : '';", "x = ok ? '...' : '';",
            "cache('feed:' . \$id)", "localStorage.setItem('ad-open:' + key, '1');", "{ok ? <i></i> : <span></span>}",
            "{{ __('Your address, as this site sees it:') }} <code>{{ \$ip }}</code>",
            // pass 4 (VF2-WA-01): the label, the language's gap and the value with no space of the markup between them
            "<div class=\"text-truncate\"><span class=\"fw-bold\">{{ __(':label:', ['label' => __('Next')]) }}</span>{{ \\App\\Support\\LabelGap::member() }}{{\$effectiveNextVideo->title}}</div>",
            "<strong>{{ rtrim(\\App\\Support\\AdminText::labelled(__('Caution'), '')) }}</strong>{{ \\App\\Support\\LabelGap::admin() }}{{__('Editing this file directly can break your application.')}}",
            "{{ \\App\\Support\\AdminText::labelled(__('Queue driver'), '') }}<code>{{ \$queueDriver }}</code>",
            "<p><b>{{ \$setting['label'] }}</b>{{ \\App\\Support\\AdminText::punctuation()['label'] }}{{ __('This setting appears in Developer mode (account menu).') }}</p>",
            "<span>{{ __(':label:', ['label' => \$detail['label']]) }}</span>",
            "<strong>{{ rtrim(\\App\\Support\\AdminText::labelled(__('Title'), '')) }}</strong> </div>",
            "<span className=\"x\">{t(':label:', {nsSeparator: false}).replace(':label', () => t('Categories'))}</span>",
            // pass 5 (VF3-WA-01): a <wbr> before the isolated value; a value element that does not isolate; a sentence
            // whose placeholder holds an isolated address (every language writes a space before it); a label inside one
            "<div class=\"ad-help\" data-webhook-url>{{ \\App\\Support\\AdminText::labelled(__('Stripe webhook address'), '') }}<wbr><code dir=\"ltr\">{{ \$url }}</code></div>",
            "<div><strong>{{ rtrim(\\App\\Support\\AdminText::labelled(__('Remote lookup'), '')) }}</strong>{{ \\App\\Support\\LabelGap::admin() }}<wbr><span dir=\"ltr\">{{ \$n }}x</span></div>",
            "<span class=\"fw-bold\">{{ __(':label:', ['label' => __('Next')]) }}</span>{{ \\App\\Support\\LabelGap::member() }}<wbr/><bdi>{{ \$title }}</bdi>",
            "<strong>{{ rtrim(\\App\\Support\\AdminText::labelled(__('Name'), '')) }}</strong>{{ \\App\\Support\\LabelGap::admin() }}<span class=\"text-muted\" data-dir=\"x\">{{ \$v }}</span>",
            "<span>{{ \\App\\Support\\AdminText::labelled(__('Title'), '') }}<strong id=\"serpTitleLength\" class=\"text-success\">0</strong>/60</span>",
            "{{ \\App\\Support\\AdminText::html(__('add a webhook for :url'), [':url' => '<code dir=\"ltr\">' . e(\$u) . '</code>']) }}",
            "<span dir=\"ltr\">{{ __(':label:', ['label' => \$name]) }}</span>",
        ];
        $catchesNextLine = [
            "<strong>{{ rtrim(\\App\\Support\\AdminText::labelled(__('Storage mode'), '')) }}</strong>\n    {{ \\Illuminate\\Support\\Str::headline(\$mode) }}.",
            "<strong>{{ rtrim(\\App\\Support\\AdminText::labelled(__('Queue Notice'), '')) }}</strong>\n\n    Broadcast dispatches use your background queue worker.",
            "<span class=\"fw-bold\">{{ __(':label:', ['label' => __('Next')]) }}</span>\n    {{ \$title }}",
        ];
        $noJoinsNextLine = [
            "<strong>{{ rtrim(\\App\\Support\\AdminText::labelled(__('Name'), '')) }}</strong>{{ \\App\\Support\\LabelGap::admin() }}\n                <p class=\"mb-0\">{{ \$name }}</p>",
            "<span>{{ __(':label:', ['label' => \$detail['label']]) }}</span>\n    <strong class=\"text-white\">{{ \$detail['value'] }}</strong>",
            "<a class=\"badge\">{{ rtrim(\\App\\Support\\AdminText::labelled(\$name, '')) }}</a>{{ \\App\\Support\\LabelGap::admin() }}\n@else",
            "<strong>{{ rtrim(\\App\\Support\\AdminText::labelled(__('Name'), '')) }}</strong>\n    {{-- the value is the block below --}}\n    <p>{{ \$name }}</p>",
        ];
        foreach ($catches as $line) {
            $this->assertNotSame([], array_filter($rules, fn (string $rule): bool => preg_match($rule, $line) === 1), 'not caught: ' . $line);
        }
        foreach ($noJoins as $line) {
            $this->assertSame([], array_keys(array_filter($rules, fn (string $rule): bool => preg_match($rule, $line) === 1)), 'caught, but no join: ' . $line);
        }
        foreach ($catchesNextLine as $text) {
            $this->assertSame(1, preg_match($nextLine, $text), 'not caught: ' . $text);
        }
        foreach ($noJoinsNextLine as $text) {
            $this->assertSame(0, preg_match($nextLine, $text), 'caught, but no space is drawn: ' . $text);
        }

        $found = [];
        foreach (['resources/views', 'resources/js', 'resources/admin', 'resources/sass'] as $folder) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($folder), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                $relative = substr($path, strlen(str_replace('\\', '/', base_path())) + 1);
                if (! preg_match('/\.(?:blade\.php|tsx?|jsx?|scss)$/', $path) || str_contains($relative, '/lang/') || str_contains($relative, '/node_modules/')
                    || in_array($relative, self::NOT_COVERED, true) || str_starts_with($relative, 'resources/views/videos/embed/')) {
                    continue;
                }
                $source = $this->source($relative);
                $lines = explode("\n", $source);
                foreach ($lines as $n => $line) {
                    if (preg_match('#^\s*(//|\*|/\*|\{\{--)#', $line)) {
                        continue;
                    }
                    foreach ($rules as $kind => $rule) {
                        if (preg_match($rule, $line, $match)) {
                            $found[] = $relative . ':' . ($n + 1) . ' [' . $kind . '] ' . trim($match[0]);
                        }
                    }
                }
                if (str_ends_with($relative, '.blade.php') && preg_match_all($nextLine, $source, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as [$text, $offset]) {
                        $n = substr_count(substr($source, 0, $offset), "\n");
                        if (! preg_match('#^\s*(//|\*|/\*|\{\{--)#', $lines[$n])) {
                            $found[] = $relative . ':' . ($n + 1) . ' [gap-next-line] ' . trim((string) strtok($text, "\n"));
                        }
                    }
                }
            }
        }
        $this->assertSame([], $found, "hard-coded ': ' joins (use ':label: :value' / ':label:', or AdminText::labelled() / AdminText::punctuation()['label'] in the admin), or a space of the markup after a label's colon (the language's gap: App\\Support\\LabelGap), or a value element that isolates its direction right after a label's colon with no <wbr> before it ([gap-isolate]: Chromium cannot break after the Chinese '：' there)");
    }

    public function test_the_playlist_editors_remove_buttons_and_the_header_search_label_follow_the_readers_punctuation(): void
    {
        $tsx = $this->source('resources/js/components/Playlists/Playlist.tsx');
        $this->assertStringNotContainsString("T.remove + ': '", $tsx);
        $this->assertStringContainsString("return (texts.pair || ':label: :value').replace(/:label|:value/g, (token) => token === ':label' ? label : value);", $tsx);
        $this->assertStringContainsString('aria-label={pair(T, T.remove, item.private ? T.private : item.title)}', $tsx);

        // the page gives the editor the pattern of its language
        $member = User::factory()->create();
        $playlist = Playlist::factory()->for($member)->create(['title' => 'Road trip songs']);
        foreach (['en', 'fr', 'zh'] as $locale) {
            $html = $locale === 'en'
                ? $this->actingAs($member)->get(route('user.playlists.edit', $playlist))->assertOk()->getContent()
                : $this->in($locale, (string) parse_url(route('user.playlists.edit', $playlist), PHP_URL_PATH), $member);
            $this->assertSame(1, preg_match("#<playlist-videos [^>]*texts='([^']*)'#", $html, $texts), $locale);
            $this->assertSame(__(':label: :value', [], $locale), json_decode(html_entity_decode($texts[1], ENT_QUOTES), true)['pair'] ?? null, $locale);
        }

        // the header search's "Categories" label: the language's label form (the key's colon is no namespace for i18next)
        $search = $this->source('resources/js/components/Search/SearchBar.tsx');
        $this->assertStringContainsString("{t(':label:', {nsSeparator: false}).replace(':label', () => t('Categories'))}", $search);
        $this->assertStringNotContainsString("{t('Categories')}:", $search);
    }

    public function test_the_admin_writes_a_label_and_its_value_in_the_admins_punctuation(): void
    {
        // the admin's search (Ctrl+K): "Page: tab" and "Section: description" with the language's separator
        foreach (['en' => ': ', 'fr' => "\u{00A0}: ", 'zh' => '：', 'ar' => ': '] as $locale => $separator) {
            app()->setLocale($locale);
            $this->assertSame($separator, AdminSearchIndex::build()['texts']['label'] ?? null, $locale);
        }
        app()->setLocale('en');
        $search = $this->source('resources/admin/lib/search.ts');
        $this->assertStringContainsString("const labelled = (label: string, value: string): string => label + (index.texts?.label ?? ': ') + value;", $search);
        $this->assertStringContainsString('title: page.p ? labelled(page.p, page.t) : page.t,', $search);
        $this->assertStringContainsString('sub: page.d ? labelled(section, page.d) : section,', $search);
        // Appearance's calendar: a day's name ("Oct 31: <look>")
        $this->assertStringContainsString("'labelSeparator' => \\App\\Support\\AdminText::punctuation()['label'],", $this->source('resources/views/admin/appearance/partials/state.blade.php'));
        $this->assertStringContainsString("const labelled = (label: string, value: string): string => label + text('labelSeparator', ': ') + value;", $this->source('resources/admin/appearance/appearance.ts'));

        // the pages: a category's edit page in Chinese and French (its title and its heading)
        $category = Category::query()->create(['title' => 'Music', 'slug' => 'music', 'in_menu' => true]);
        foreach (['zh', 'fr', 'en'] as $locale) {
            $admin = User::factory()->admin()->create(['language' => $locale]);
            $html = (string) $this->actingAs($admin)->withSession(['app_locale' => $locale])->get(route('admin.categories.edit', $category))->assertOk()->getContent();
            app()->setLocale($locale);
            $line = AdminText::labelled(__('Edit Category'), 'Music');
            app()->setLocale('en');
            $this->assertStringContainsString('</i>' . e($line) . '</h3>', $html, $locale);
            $this->assertMatchesRegularExpression('#<title>[^<]*' . preg_quote(e($line), '#') . '#u', $html, $locale);
        }
        app()->setLocale('zh');
        $this->assertSame('编辑分类：Music', AdminText::labelled('编辑分类', 'Music'));
        app()->setLocale('fr');
        $this->assertSame("Modifier la catégorie\u{00A0}: Music", AdminText::labelled('Modifier la catégorie', 'Music'));
        app()->setLocale('en');

        // the old admin's Reports: the commenter's pill ("name:", never "name : ")
        app()->setLocale('zh');
        $report = (object) ['reportable' => (object) ['video' => null, 'user' => (object) ['username' => 'nightdrive_tv', 'route' => '/channel/nightdrive_tv'], 'content' => 'Nice']];
        $pill = view('reports.comment', ['report' => $report])->render();
        $this->assertStringContainsString('>nightdrive_tv：</a>', $pill);
        app()->setLocale('en');
        $this->assertStringContainsString('>nightdrive_tv:</a>', view('reports.comment', ['report' => $report])->render());
    }

    /**
     * Pass 3 (independent check VF1-WA-01): the two joins the rule's earlier form missed (a colon after a closing tag).
     * The new admin's "Developer mode is off" page, opened from the admin search for one of its settings
     * (?setting=key), names the setting and its sentence in the admin's punctuation, the setting's name still bold; the
     * old admin's cache purge writes its time and the server's (translated) message the same way.
     */
    public function test_the_developer_mode_page_and_the_old_admins_cache_result_join_their_parts_in_the_admins_punctuation(): void
    {
        config(['admin.ui' => 'v2']);
        $setting = \App\Support\AdminSettingsCatalog::setting('cors_allowed_origins');
        $this->assertIsArray($setting);
        foreach (['fr' => "\u{00A0}: ", 'zh' => '：', 'en' => ': ', 'ar' => ': '] as $locale => $separator) {
            $this->assertSame($separator, AdminText::punctuation($locale)['label'], $locale);
            $admin = User::factory()->admin()->create(['language' => $locale]);
            \App\Support\AdminPreferences::set($admin, 'developer_mode', false);
            $html = (string) $this->actingAs($admin->fresh())->withSession(['app_locale' => $locale])
                ->get(route('admin.env-editor.index', ['setting' => 'cors_allowed_origins']))->assertForbidden()->getContent();
            app()->setLocale($locale);
            $label = (string) __($setting['label']);
            $sentence = (string) __('This setting appears in Developer mode (account menu).');
            $expected = AdminText::labelled($label, $sentence);
            app()->setLocale('en');
            $this->assertStringContainsString('<p style="margin:0 0 6px"><b>' . e($label) . '</b>' . e($separator) . e($sentence) . '</p>', $html, $locale);
            $this->assertSame(1, preg_match('#<p style="margin:0 0 6px">(.*?)</p>#su', $html, $line), $locale);
            $this->assertSame($expected, html_entity_decode(strip_tags($line[1]), ENT_QUOTES | ENT_HTML5), $locale);
        }

        // the old admin (admin_ui v1, the tests' default): Settings › System › clear a cache, "<time>: <message>"
        config(['admin.ui' => 'v1']);
        foreach (['fr' => "\u{00A0}: ", 'zh' => '：', 'en' => ': '] as $locale => $separator) {
            $admin = User::factory()->admin()->create(['language' => $locale]);
            $html = (string) $this->actingAs($admin)->withSession(['app_locale' => $locale])
                ->get(route('admin.settings.index', ['tab' => 'system']))->assertOk()->getContent();
            $json = json_encode($separator, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
            $this->assertStringContainsString("<strong>' + data.timestamp + '</strong>' + " . $json . ' + data.message;', $html, $locale);
            $this->assertStringNotContainsString("'</strong>: ' + data.message", $html, $locale);
            $this->assertSame($separator, json_decode((string) $json), $locale);
        }
    }

    // ------------------------------------------------------------------------------------------- VF2-WA-01 (pass 4)

    /**
     * The French and Chinese patterns of this lane's i18n.json (":label:" / ":label: :value") where the language files do
     * not have them yet (the copy as delivered keeps int-C4's language files; after the merge they are there and this
     * changes nothing). The language's file is read first: addLines() would otherwise stand for all of it.
     */
    private function labelPatterns(): void
    {
        foreach (['fr' => [':label:' => ":label\u{00A0}:", ':label: :value' => ":label\u{00A0}: :value"], 'zh' => [':label:' => ':label：', ':label: :value' => ':label：:value']] as $locale => $lines) {
            app('translator')->get('Next', [], $locale);
            foreach ($lines as $key => $value) {
                if (app('translator')->get($key, [], $locale, false) === $key) {
                    app('translator')->addLines(['*.' . $key => $value], $locale);
                }
            }
        }
    }

    /**
     * VF2-WA-01: between a label's colon and its value each language writes its own gap (App\Support\LabelGap): a space,
     * nothing after the Chinese full-width colon. With it a label printed as its own element reads exactly as the
     * language's "label: value".
     */
    public function test_label_gap_is_what_the_language_writes_between_a_labels_colon_and_its_value(): void
    {
        $this->labelPatterns();
        foreach (['en' => ' ', 'fr' => ' ', 'zh' => '', 'ar' => ' ', 'ja' => ' ', 'de' => ' ', 'ru' => ' '] as $locale => $gap) {
            // the admin: the rest of AdminText's label separator after the colon
            $this->assertSame($gap, \App\Support\LabelGap::admin($locale), 'admin ' . $locale);
            app()->setLocale($locale);
            $this->assertSame($gap, \App\Support\LabelGap::admin(), 'admin, the admin\'s language ' . $locale);
            $this->assertSame(AdminText::labelled('Caution', 'Editing'), rtrim(AdminText::labelled('Caution', '')) . \App\Support\LabelGap::admin() . 'Editing', $locale);
            app()->setLocale('en');
            // the member side: what ":label: :value" writes after ":label:"
            $this->assertSame($gap, \App\Support\LabelGap::member($locale), 'member ' . $locale);
            $this->assertSame(__(':label: :value', ['label' => 'Next', 'value' => 'Title'], $locale), __(':label:', ['label' => 'Next'], $locale) . \App\Support\LabelGap::member($locale) . 'Title', $locale);
        }
        $this->assertSame('', \App\Support\LabelGap::admin('zh-CN'));
        $this->assertSame(' ', \App\Support\LabelGap::admin('fr-CA'));

        // a pattern that does not start with the label's own form, or puts more than a space there: a plain space
        app('translator')->addLines(['*.:label:' => ':label :', '*.:label: :value' => '[:label] :value'], 'xx');
        $this->assertSame(' ', \App\Support\LabelGap::member('xx'));
        app('translator')->addLines(['*.:label:' => ':label:', '*.:label: :value' => ':label: - :value'], 'xy');
        $this->assertSame(' ', \App\Support\LabelGap::member('xy'));
    }

    /**
     * VF2-WA-01: the watch page's playlist line ("Next: <title>", the collapsed playlist's summary) is the language's
     * label and value: the label bold with its colon, then the language's gap, then the title ("下一个：标题" in
     * Chinese; it showed "下一个： 标题"), the same as before in every other language.
     */
    public function test_the_watch_pages_next_line_reads_as_the_languages_label_and_value(): void
    {
        $this->withoutVite();
        $this->labelPatterns();
        $creator = User::factory()->create();
        $playlist = Playlist::factory()->for($creator)->create(['title' => 'Night drives', 'status' => \App\Enums\PlaylistStatus::PUBLIC, 'sort' => \App\Enums\PlaylistSort::MANUAL]);
        $first = Video::factory()->for($creator)->withStatus(VideoStatus::PUBLIC)->create(['title' => 'Drone flight over the pine valley', 'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);
        $second = Video::factory()->for($creator)->withStatus(VideoStatus::PUBLIC)->create(['title' => 'Late night convenience store run', 'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);
        $playlist->videos()->attach($first->id, ['position' => 1, 'added_at' => now()->subMinutes(2)]);
        $playlist->videos()->attach($second->id, ['position' => 2, 'added_at' => now()->subMinute()]);
        $path = (string) parse_url($first->route, PHP_URL_PATH) . '?list=' . $playlist->uuid;

        $lines = [];
        foreach (['en', 'fr', 'zh', 'ja', 'ar'] as $locale) {
            $this->flushSession();
            $html = $locale === 'en' ? $this->get($path)->assertOk()->getContent() : $this->in($locale, $path);
            $this->assertSame(1, preg_match('#<div class="text-truncate"><span class="fw-bold">(.*?)</span>(.*?)</div>#su', $html, $line), $locale . ': the playlist line');
            $label = __(':label:', ['label' => __('Next', [], $locale)], $locale);
            $this->assertSame(e($label), $line[1], $locale . ': the label, bold, with its colon');
            $this->assertSame(e(\App\Support\LabelGap::member($locale)) . e($second->title), $line[2], $locale . ': the gap, then the title (no space of the markup)');
            $this->assertSame(__(':label: :value', ['label' => __('Next', [], $locale), 'value' => $second->title], $locale), html_entity_decode(strip_tags($line[0]), ENT_QUOTES | ENT_HTML5), $locale);
            $lines[$locale] = html_entity_decode(strip_tags($line[0]), ENT_QUOTES | ENT_HTML5);
        }
        $this->assertSame('Next: Late night convenience store run', $lines['en']);
        $this->assertSame(__('Next', [], 'fr') . "\u{00A0}: Late night convenience store run", $lines['fr']);
        $this->assertSame(__('Next', [], 'zh') . '：Late night convenience store run', $lines['zh']);
        // the HTML of every other language as before: "<span class="fw-bold">Next:</span> <title>" (a fresh session: the
        // last page's language would send the visitor to its address)
        $this->flushSession();
        $this->assertSame(1, preg_match('#<span class="fw-bold">Next:</span> Late night convenience store run</div>#', $this->get($path)->assertOk()->getContent()));
    }

    /**
     * VF2-WA-01: the admin's labels printed as their own element (or as text before their value's element) are followed by
     * the language's gap and the value, never by a space of the markup, on the ten admin pages pass 2 gave the language's
     * colon: the old admin's message (contacts/show), Reports (the commenter's pill), View Analytics, Scheduled tasks,
     * the .env editor, AVDBAPI import's Quick Help, Queue monitor, "Horizon unavailable", Settings (Notifications' Queue
     * Notice, SEO's snippet counters), and the new admin's Money > Payment setup (the webhook addresses). In Chinese no
     * space follows "：" ("注意：直接编辑…"); English, French and Arabic read as before (the English bytes are int-C4's).
     * Pass 5 (VF3-WA-01): a value element that isolates its direction (the webhook addresses' <code dir="ltr">, Remote
     * lookup's <span dir="ltr">) has a <wbr> right before it in every language, so a phone can break after the Chinese
     * "：" and keeps the label whole (Chromium finds no break between "：" and such an element); the <wbr> has no width
     * and no character: the English bytes are int-C4's with it, the text the same.
     * Every assertion reads a line cut out of its page (a failure never prints a page: the .env editor shows the file).
     */
    public function test_the_admins_label_lines_read_as_the_languages_label_and_value_with_no_space_of_the_markup(): void
    {
        $commenter = User::factory()->create(['username' => 'wa_viewer']);
        $video = Video::factory()->for($commenter)->withStatus(VideoStatus::PUBLIC)->create(['title' => 'Drone flight over the pine valley']);
        $comment = \App\Models\Comment::factory()->for($video)->for($commenter)->create(['content' => 'A comment for the label check.']);
        \App\Models\Report::factory()->for($comment, 'reportable')->create();
        $message = DB::table('contact_messages')->insertGetId(['name' => 'Probe Visitor', 'email' => 'probe.visitor@example.test', 'subject' => 'Label check', 'message' => 'Hello', 'ip_address' => '203.0.113.7', 'is_read' => false, 'created_at' => now(), 'updated_at' => now()]);
        $uuid = (string) \Illuminate\Support\Str::uuid();
        DB::table('jobs')->insert(['queue' => 'wa-probe', 'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\WaLabelProbe', 'data' => []]), 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->addYear()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        // Redis does not answer: /admin/horizon-link shows "Horizon unavailable"
        foreach (['default', 'cache', 'queue'] as $connection) {
            config(["database.redis.$connection.host" => '127.0.0.1', "database.redis.$connection.port" => 9]);
        }

        $cut = function (string $html, string $pattern, string $what): string {
            $this->assertSame(1, preg_match($pattern, $html, $match), $what . ': the line is on its page');

            return $match[0];
        };
        $seen = [];
        foreach (['en', 'fr', 'zh', 'ar'] as $locale) {
            $admin = User::factory()->admin()->create(['language' => $locale]);
            $page = function (string $url, string $ui) use ($admin, $locale): string {
                config(['admin.ui' => $ui]);

                return (string) $this->actingAs($admin)->withSession(['app_locale' => $locale])->get($url)->assertOk()->getContent();
            };
            app()->setLocale($locale);
            $gap = \App\Support\LabelGap::admin();
            $head = fn (string $key): string => e(rtrim(AdminText::labelled(__($key), '')));
            $text = fn (string $key): string => e(AdminText::labelled(__($key), ''));
            $sentence = fn (string $key): string => preg_quote(e(__($key)), '#');
            $quote = fn (string $html): string => preg_quote($html, '#');
            $lines = [
                // the old admin (admin_ui v1)
                'contacts' => [route('admin.contacts.show', $message), 'v1', array_map(fn (string $key): string => '#<strong>' . $quote($head($key)) . '</strong>' . $quote(e($gap)) . '\n#u', ['Name', 'Email', 'Subject', 'Date', 'IP Address', 'Message'])],
                'reports' => [route('admin.reports.index'), 'v1', ['#>' . $quote(e(rtrim(AdminText::labelled('wa_viewer', '')))) . '</a>' . $quote(e($gap)) . '\n#u']],
                'views' => [route('admin.views.index'), 'v1', ['#<strong>' . $quote($head('Storage mode')) . '</strong>' . $quote(e($gap)) . '[A-Z][A-Za-z ]*\.\n#u']],
                // Remote lookup's value "8x / 15s" is an isolated <span dir="ltr">: a <wbr> before it (pass 5, VF3-WA-01)
                'cron' => [route('admin.cron.index'), 'v1', array_map(fn (string $key): string => '#<strong>' . $quote($head($key)) . '</strong>' . $quote(e($gap)) . ($key === 'Remote lookup' ? '<wbr><span dir="ltr">' : '[^\s<]') . '#u', ['Process support', 'Async queue', 'Remote lookup', 'Last attempt'])],
                'env' => [route('admin.env-editor.index'), 'v1', ['#<strong>' . $quote($head('Caution')) . '</strong>' . $quote(e($gap)) . $sentence('Editing this file directly can break your application. A backup is created automatically on each save. Changes take effect immediately after config cache is cleared.') . '#u']],
                'avdb' => [route('admin.avdb-import.index'), 'v1', array_map(fn (string $key): string => '#<div><strong>' . $quote($head($key)) . '</strong>' . $quote(e($gap)) . '\S#u', ['Endpoint', 'Page Size', 'Content Language', 'Title Source', 'Full 16-Lang', 'Filters', 'Import dates', 'Visibility', 'Thumbnails', 'Duplicates'])],
                'queue' => [route('admin.queue.index'), 'v1', ['#' . $quote($text('UUID')) . '<code>' . $quote($uuid) . '</code>#u']],
                'horizon' => [route('admin.horizon'), 'v1', ['#' . $quote($text('Queue driver')) . '<code>' . $quote(e((string) config('queue.default'))) . '</code>#u', '#' . $quote($text('Horizon Redis connection')) . '<code>' . $quote(e((string) config('horizon.use', 'default'))) . '</code>#u']],
                'settings' => [route('admin.settings.index'), 'v1', ['#<strong>' . $quote($head('Queue Notice')) . '</strong>' . $quote(e($gap)) . $sentence('Broadcast dispatches use your background queue worker. Ensure queue worker is running so notifications deliver instantly.') . '#u', '#<span>' . $quote($text('Title')) . '<strong id="serpTitleLength" #u', '#<span>' . $quote($text('Description')) . '<strong id="serpDescLength" #u']],
                // the new admin (v2); the address is an isolated <code dir="ltr">: a <wbr> before it (pass 5, VF3-WA-01)
                'money' => [route('admin.money.setup'), 'v2', ['#' . $quote($text('Stripe webhook address')) . '<wbr><code dir="ltr">' . $quote(e(route('stripe_webhook'))) . '</code>#u', '#' . $quote($text('PayPal webhook address')) . '<wbr><code dir="ltr">' . $quote(e(route('paypal_webhook'))) . '</code>#u']],
            ];
            app()->setLocale('en');
            foreach ($lines as $key => [$url, $ui, $patterns]) {
                $html = $page($url, $ui);
                foreach ($patterns as $n => $pattern) {
                    $line = $cut($html, $pattern, $locale . ' ' . $key . ' #' . $n);
                    $seen[$locale][$key . ' #' . $n] = $line;
                    if ($locale === 'zh') {
                        // the full-width colon is followed by the value (or the block below), never by a space
                        $this->assertSame(0, preg_match('/：(?:<\/[a-z]+>)*(?: |&nbsp;)/u', $line), $key . ' #' . $n . ': ' . $line);
                    }
                    // pass 5 (VF3-WA-01), every language: after the label's colon and gap, a value element that isolates
                    // its direction comes only after a <wbr> (the markup is the same in every language)
                    $this->assertSame(0, preg_match('#[:：](?:</[a-z]+>|[ \x{00A0}])*<(?!wbr\b)(?:bd[io]\b|[a-z][a-z0-9]*\s[^>]*(?<=\s)dir=)#u', $line), $locale . ' ' . $key . ' #' . $n . ': ' . $line);
                }
            }
        }
        // English as before, byte for byte (int-C4's markup): the bold label with its colon, a space, the value
        $caution = 'Editing this file directly can break your application. A backup is created automatically on each save. Changes take effect immediately after config cache is cleared.';
        $driver = '<code>' . e((string) config('queue.default')) . '</code>';
        $this->assertSame('<strong>Caution:</strong> ' . $caution, $seen['en']['env #0']);
        $this->assertSame('<strong>Process support:</strong> ', substr($seen['en']['cron #0'], 0, 34));
        $this->assertSame('Queue driver: ' . $driver, $seen['en']['horizon #0']);
        $this->assertSame('<span>Title: <strong id="serpTitleLength" ', $seen['en']['settings #1']);
        // French: the no-break space before the colon, a space after it; Chinese: "：" and the value at once
        $this->assertSame('<strong>' . e(__('Caution', [], 'fr')) . "\u{00A0}:</strong> " . e(__($caution, [], 'fr')), $seen['fr']['env #0']);
        $this->assertSame('<strong>' . e(__('Caution', [], 'zh')) . '：</strong>' . e(__($caution, [], 'zh')), $seen['zh']['env #0']);
        $this->assertSame(e(__('Queue driver', [], 'zh')) . '：' . $driver, $seen['zh']['horizon #0']);
        // pass 5 (VF3-WA-01): the <wbr> before an isolated value, with nothing else added: English is int-C4's line with it
        // ("Stripe webhook address: <code dir="ltr">…" in int-C4), French keeps its gap, Chinese has "：" and the <wbr>
        $stripe = '<code dir="ltr">' . e(route('stripe_webhook')) . '</code>';
        $this->assertSame('Stripe webhook address: <wbr>' . $stripe, $seen['en']['money #0']);
        $this->assertSame('<strong>Remote lookup:</strong> <wbr><span dir="ltr">', $seen['en']['cron #2']);
        $this->assertSame(e(__('Stripe webhook address', [], 'fr')) . "\u{00A0}: <wbr>" . $stripe, $seen['fr']['money #0']);
        $this->assertSame(e(__('Stripe webhook address', [], 'zh')) . '：<wbr>' . $stripe, $seen['zh']['money #0']);
        $this->assertSame('<strong>' . e(__('Remote lookup', [], 'zh')) . '：</strong><wbr><span dir="ltr">', $seen['zh']['cron #2']);

        // the Reports pill of a deleted member (reports/comment.blade.php's other pill, above the comment's excerpt): the same form
        $report = (object) ['reportable' => (object) ['video' => null, 'user' => null, 'content' => 'Nice']];
        foreach (['zh' => '', 'en' => ' ', 'fr' => ' '] as $locale => $gap) {
            app()->setLocale($locale);
            $pill = view('reports.comment', ['report' => $report])->render();
            $expected = '<i class="fa-solid fa-user-slash me-1"></i>' . e(rtrim(AdminText::labelled(__('Deleted user'), ''))) . '</span>' . $gap . "\n";
            app()->setLocale('en');
            $this->assertSame(1, substr_count($pill, $expected), $locale . ': the deleted member\'s pill, then the language\'s gap');
        }
    }

    // ------------------------------------------------------------------------------------------- RV-WA-02

    public function test_the_watch_pages_date_and_a_planned_videos_line_follow_the_sites_date_rules(): void
    {
        config(['app.timezone' => 'Europe/Paris', 'site.date_format' => 'M d, Y']);
        $now = CarbonImmutable::create(2026, 10, 29, 12, 0, 0, 'Europe/Paris');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
        $this->withoutVite();

        $published = CarbonImmutable::create(2026, 9, 18, 10, 0, 0, 'UTC');
        $video = Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->create(['title' => 'Downtown jazz club live set', 'duration' => 125, 'views' => 1234, 'published_at' => $published]);
        $path = (string) parse_url($video->route, PHP_URL_PATH);
        // the language's own order (it was "d F Y" in every language: "18 九月 2026", "18 9月 2026", "18 September 2026")
        // (Arabic already wrote the day first: the same words as before)
        $expected = ['en' => 'Sep 18, 2026', 'fr' => '18 sept. 2026', 'ja' => '2026年9月18日', 'zh' => '2026年9月18日', 'ar' => '18 سبتمبر 2026', 'de' => null, 'ko' => null];
        $old = ['en' => '18 September 2026', 'fr' => '18 septembre 2026', 'ja' => '18 9月 2026', 'zh' => '18 九月 2026', 'ar' => null, 'de' => '18 September 2026', 'ko' => '18 9월 2026'];
        foreach ($expected as $locale => $date) {
            $html = $locale === 'en' ? $this->get($path)->assertOk()->getContent() : $this->in($locale, $path);
            app()->setLocale($locale);
            $made = SiteDate::date($published);
            app()->setLocale('en');
            if ($date !== null) {
                $this->assertSame($date, $made, $locale);
            }
            $this->assertMatchesRegularExpression('#<span aria-hidden="true">&middot;</span>\s*<span>' . preg_quote(e($made), '#') . '</span>#u', $html, $locale);
            if ($old[$locale] !== null) {
                $this->assertNotSame($old[$locale], $made, $locale);
                $this->assertStringNotContainsString('<span>' . e($old[$locale]) . '</span>', $html, $locale);
            }
        }
        // the admin's numeric format, in every language
        config(['site.date_format' => 'd/m/Y']);
        $this->assertStringContainsString('<span>18/09/2026</span>', $this->in('zh', $path));
        config(['site.date_format' => 'M d, Y']);

        // a planned video's line, as its owner sees it: the studio's words in the site's zone (a fresh session: the last
        // page's language would send the owner to its address)
        $this->flushSession();
        $owner = User::factory()->create();
        $planned = Video::factory()->for($owner)->withStatus(VideoStatus::PLANNED)->create(['title' => 'Planned premiere', 'scheduled_at' => CarbonImmutable::create(2026, 10, 31, 20, 0, 0, 'Europe/Paris')]);
        $html = $this->actingAs($owner)->get((string) parse_url($planned->route, PHP_URL_PATH))->assertOk()->getContent();
        $line = ZoneLabel::goesPublic($planned->scheduled_at);
        $this->assertSame('Goes public Oct 31, 2026, 20:00 GMT+1', $line);
        $this->assertStringContainsString('<strong>' . e($line) . '</strong>', $html);
        $this->assertStringNotContainsString('Planned - ', $html);

        $source = $this->source('resources/views/videos/show.blade.php');
        $this->assertStringNotContainsString("translatedFormat('d F Y')", $source);
        $this->assertStringNotContainsString("translatedFormat('d M Y H:i')", $source);
    }

    // ------------------------------------------------------------------------------------------- RV-WA-03, RV-WA-04

    public function test_the_bell_sheet_is_never_taken_over_by_bootstraps_own_offcanvas_and_its_menu_button_opens_on_keys(): void
    {
        $tsx = $this->source('resources/js/components/Notifications/NotificationsList.tsx');

        // RV-WA-03: the sheet's own mark, and Bootstrap's show refused for it (the window's load event showed it again)
        $this->assertStringContainsString('aria-labelledby="avd-bell-title" data-avd-bell-sheet="">', $tsx);
        $this->assertStringContainsString("const BELL_SHEET = 'data-avd-bell-sheet';", $tsx);
        $this->assertMatchesRegularExpression('/const refuseBootstrapShow = \(event: Event\) => \{\s*if \(event\.target instanceof Element && event\.target\.hasAttribute\(BELL_SHEET\)\) \{\s*event\.preventDefault\(\);/', $tsx);
        $this->assertStringContainsString("document.addEventListener('show.bs.offcanvas', refuseBootstrapShow, true);", $tsx);

        // RV-WA-04: the closed menu's button takes Enter, Space, Down and Up itself, and the focus moves in an effect of
        // the menu's state (react-bootstrap's own "opened by a key" memory was reset by any render in between)
        $this->assertStringContainsString("const OPEN_KEYS: ReadonlyArray<string> = ['Enter', ' ', 'ArrowDown', 'ArrowUp'];", $tsx);
        $this->assertMatchesRegularExpression("/if \\(toggle && target && toggle\\.contains\\(target\\) && OPEN_KEYS\\.includes\\(event\\.key\\)\\s*&& !event\\.altKey && !event\\.ctrlKey && !event\\.metaKey\\) \\{\\s*event\\.preventDefault\\(\\);\\s*event\\.stopPropagation\\(\\);/", $tsx);
        $this->assertStringContainsString("focusOnOpen.current = {where: event.key === 'ArrowUp' ? 'last' : 'first', at: Date.now()};", $tsx);
        $this->assertMatchesRegularExpression('/useEffect\(\(\) => \{\s*if \(!menuOpen\) \{\s*return;\s*\}\s*const mark = focusOnOpen\.current;/', $tsx);
        $this->assertStringContainsString("(mark.where === 'last' ? items[items.length - 1] : items[0])?.focus();", $tsx);
        // the keys are taken while the sheet is open (not only while the menu is), and the Space that opened the menu
        // does not click its button again on keyup
        $this->assertMatchesRegularExpression('/useEffect\(\(\) => \{\s*if \(!open\) \{\s*return undefined;\s*\}\s*const onKey =/', $tsx);
        $this->assertStringContainsString("window.addEventListener('keyup', onKeyUp, true);", $tsx);
        $this->assertStringContainsString('}, [open, menuOpen, confirming]);', $tsx);
    }

    // ------------------------------------------------------------------------------------------- RV-WA-05, RV-WA-06

    /**
     * A member who watched one video a day, as the copy's data has it: its row read back whole (the session loads it so:
     * "Pause history" shows), videos with UUID links ("/video/<slug>-<uuid>", as imported videos have them).
     */
    private function historyMember(int $days): array
    {
        $member = User::factory()->create()->fresh();
        $names = ['nightdrive_tv', 'oceanlens', 'cabin_builders', 'street_bites', 'lofi_room', 'sandboxadmin'];
        $titles = ['Tokyo night walk in the rain - 4K binaural city ambience', 'How we built a tiny cabin in 30 days (full build)', 'Morning stretch flow for beginners (20 min)', 'Behind the scenes: our studio setup tour', 'Sailing across the Atlantic - episode 4', 'Snowboarding the backcountry with friends', 'The quiet library - 3 hour reading room', 'Downtown jazz club live set', 'Ultimate guide to film photography', 'Aurora hunting - we finally saw it'];
        $channels = collect($names)->map(fn (string $name) => User::factory()->create(['username' => $name, 'slug' => \Illuminate\Support\Str::studly($name), 'show_subscribers' => true]));
        $videos = [];
        foreach (array_slice($titles, 0, $days) as $n => $title) {
            $videos[] = $video = Video::factory()->for($channels[$n % 6])->withStatus(VideoStatus::PUBLIC)->create(['title' => $title, 'slug' => \Illuminate\Support\Str::slug($title), 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'views' => 87800 + 97 * $n, 'duration' => 2400 + 977 * $n, 'poster_url' => sprintf('/images/tr/thumb_%02d.jpg', $n + 1), 'published_at' => now()->subMonth(), 'uploaded_at' => now()->subMonth()]);
            DB::table('views')->insert(['video_id' => $video->id, 'user_id' => $member->id, 'ip' => '127.0.0.1', 'user_agent' => 'test', 'view_at' => now()->subDays($n)->subMinutes(5)]);
        }

        return [$member, $videos];
    }

    public function test_one_video_a_day_still_fits_historys_content_budget_with_the_same_markup_in_every_language(): void
    {
        // RV-WA-05: an ordinary "one video a day" history fills the first page with 8 rows in 8 day groups (16,987 B raw in
        // English before: over the 16 KB the ruling keeps on English); the ruling of 29 Oct 20:45 for the other languages
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 21, 0, 0, 'UTC'));
        [$member] = $this->historyMember(10);
        $measured = [];
        $english = null;
        foreach (['en', 'ar', 'hi', 'ru', 'th', 'ja', 'fr', 'zh'] as $locale) {
            // a fresh session per language (the session keeps the last one)
            $this->flushSession();
            $root = $this->pageRoot($locale === 'en' ? $this->actingAs($member)->get(route('pages.history'))->assertOk()->getContent() : $this->in($locale, '/history', $member));
            $nodes = preg_match_all('/<[a-zA-Z]/', $root);
            $gzip = strlen((string) gzencode($root, 9));
            $markupText = $this->markupOnly($root);
            $markup = strlen((string) gzencode($markupText, 9));
            $label = sprintf('History, one video a day (%s): %d B, %d B gzip (markup %d B), %d nodes', $locale, strlen($root), $gzip, $markup, $nodes);
            $measured[] = $label;
            $this->assertSame(8, preg_match_all('/data-history-day="/', $root), $label . ': 8 day groups');
            if ($locale === 'en') {
                $english = ['nodes' => $nodes, 'markup' => $markup, 'text' => $markupText];
                $this->assertLessThanOrEqual(16 * 1024, strlen($root), $label);
                $this->assertLessThanOrEqual(3 * 1024, $gzip, $label);
                $this->assertLessThanOrEqual(250, $nodes, $label);
                continue;
            }
            $this->assertSame($english['nodes'], $nodes, $label . ': the same tags as English');
            $this->assertLessThanOrEqual(16, abs($markup - $english['markup']), $label . ': the same markup as English');
            // byte for byte, words taken out: a day's question is printed by the day, never by its words
            $this->assertSame($english['text'], $markupText, $label . ': the markup identical to English');
            $this->assertLessThanOrEqual((int) floor(3 * 1024 * 1.1), $gzip, $label . ': at most 10 % over, by its words');
        }
        fwrite(STDERR, PHP_EOL . implode(PHP_EOL, $measured) . PHP_EOL);
    }

    public function test_history_prints_a_rows_menu_once_and_a_days_question_only_where_it_is_its_own(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 21, 0, 0, 'UTC'));
        [$member] = $this->historyMember(4);
        foreach (['en', 'fr', 'ja'] as $locale) {
            $html = $locale === 'en' ? $this->actingAs($member)->get(route('pages.history'))->assertOk()->getContent() : $this->in($locale, '/history', $member);
            $root = $this->pageRoot($html);

            // every row's menu button, and the menu itself once, as the page's template (its words were in every row)
            $this->assertSame(4, substr_count($root, '<div class="dropdown mk-lrow__menu"><button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-toggle="dropdown" aria-expanded="false" aria-label="' . e(__('More actions', [], $locale)) . '"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button></div></li>'), $locale);
            $this->assertSame(1, substr_count($root, '<template data-history-row-menu><div class="dropdown-menu dropdown-menu-end mk-menu" role="menu"><button type="button" class="dropdown-item mk-menu__item" role="menuitem" data-history-remove><i class="fa-solid fa-xmark" aria-hidden="true"></i>' . e(__('Remove from history', [], $locale)) . '</button></div></template>'), $locale);
            $this->assertSame(1, substr_count($root, 'data-history-remove>'), $locale);
            if ($locale === 'en') {
                $this->assertSame(1, substr_count($root, 'Remove from history'), 'the words once');
            }

            // a day's Remove: its date is its group's; its question is printed only for today and yesterday, an older
            // day's is the dialog's template with the day's name, the same sentence the server makes
            app()->setLocale($locale);
            $today = now()->timezone(config('app.timezone'))->format('Y-m-d');
            $yesterday = now()->timezone(config('app.timezone'))->subDay()->format('Y-m-d');
            $older = now()->timezone(config('app.timezone'))->subDays(2)->format('Y-m-d');
            $todayQuestion = WatchHistory::removeDayQuestion($today);
            $yesterdayQuestion = WatchHistory::removeDayQuestion($yesterday);
            $template = __('Remove the history of :day?');
            $olderQuestion = WatchHistory::removeDayQuestion($older);
            $olderLabel = WatchHistory::dayLabel($older);
            app()->setLocale('en');
            $this->assertSame(4, substr_count($root, 'data-history-remove-day=""'), $locale);
            $this->assertSame(2, substr_count($root, ' data-day-question="'), $locale);
            $this->assertStringContainsString('data-day-question="' . e($todayQuestion) . '"', $root, $locale);
            $this->assertStringContainsString('data-day-question="' . e($yesterdayQuestion) . '"', $root, $locale);
            $this->assertStringNotContainsString(e($olderQuestion), $root, $locale);
            $this->assertStringContainsString('data-remove-day-question="' . e($template) . '"', $root, $locale);
            $this->assertSame($olderQuestion, str_replace(':day', $olderLabel, $template), $locale . ': the script makes the server\'s sentence');
            $this->assertStringContainsString('<h2 class="mk-day__title" id="mkDay' . str_replace('-', '', $older) . '">' . e($olderLabel) . '</h2>', $root, $locale);
        }

        // the page script: the row's menu from the template before Bootstrap's handler (delegated from the document in the
        // capture phase: the copy is put in on the window, earlier), the day from its group, the
        // question from the dialog's template (and never a day's confirmation without its day)
        $script = $this->source('resources/js/member/library-pages.js');
        foreach ([
            "const rowMenu = root.querySelector('template[data-history-row-menu]');",
            "toggle.after(rowMenu.content.firstElementChild.cloneNode(true));",
            "window.addEventListener('click', giveMenu, true);",
            "window.addEventListener('keydown', giveMenu, true);",
            "const day = button.getAttribute('data-history-remove-day') || section?.getAttribute('data-history-day') || '';",
            'if (!day) return;',
            "? data.removeDayQuestion.replace(/:day|:Day|:DAY/g,",
        ] as $now) {
            $this->assertStringContainsString($now, $script, $now);
        }
    }

    public function test_an_empty_history_prints_its_list_empty(): void
    {
        // RV-WA-06: printed with nothing in it, the list is empty, so the kit's :empty rule hides it as it does after "Clear
        // all" (the page's own empty state; RC2's printed list held line breaks and kept a gap above the message)
        $member = User::factory()->create();
        foreach ([route('pages.history'), route('pages.history', ['q' => 'zzznomatch'])] as $url) {
            $root = $this->pageRoot($this->actingAs($member)->get($url)->assertOk()->getContent());
            $this->assertStringContainsString('<div class="mk-history__list" data-history-list></div>', $root, $url);
        }
        $this->assertMatchesRegularExpression('/:root \.mk-history__list:empty \{\s*display: none;/', $this->source('resources/sass/member/_channel.scss'));
    }
}
