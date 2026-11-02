<?php

namespace Tests\Unit;

use App\Support\Studio\StudioHtml;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Lean studio HTML (member design 4.3, studio-20): the squeeze keeps what a browser renders, never touches
 * scripts, textareas or <pre>, and makes the site's own links root-relative; countWords gives the exact counted words.
 */
class StudioHtmlTest extends TestCase
{
    public function test_template_indentation_between_tags_goes_and_text_stays(): void
    {
        $html = "<ul>\n        <li>One</li>\n        <li>Two  words</li>\n    </ul>\n    <p>Hello,\n    world</p>";

        $this->assertSame("<ul>\n<li>One</li>\n<li>Two  words</li>\n</ul>\n<p>Hello,\n    world</p>", StudioHtml::squeeze($html));
    }

    public function test_a_space_between_inline_elements_is_kept_as_one_space(): void
    {
        $this->assertSame('<b>a</b> <i>b</i> <s>c</s>', StudioHtml::squeeze('<b>a</b>      <i>b</i> <s>c</s>'));
        $this->assertSame("<p>x</p>\n", StudioHtml::squeeze("<p>x</p>\n    "));
        $this->assertSame("<b>a</b>\n<i>b</i>", StudioHtml::squeeze("<b>a</b>   \n\n   <i>b</i>"));
    }

    public function test_scripts_textareas_and_pre_blocks_are_copied_as_they_are(): void
    {
        $script = "<script>\n    var a = 1;\n    // a comment\n    if (a) {\n        a++;\n    }\n</script>";
        $json = "<script type=\"application/json\" id=\"x\">{\"a\":\n    \"b\"}</script>";
        $textarea = "<textarea name=\"description\">Line one\n    indented line\n\n        </textarea>";
        $pre = "<pre>  keep\n      this  </pre>";
        $html = "<div>\n    ".$script."\n    ".$json."\n    ".$textarea."\n    ".$pre."\n</div>";

        $out = StudioHtml::squeeze($html);

        $this->assertStringContainsString($script, $out);
        $this->assertStringContainsString($json, $out);
        $this->assertStringContainsString($textarea, $out);
        $this->assertStringContainsString($pre, $out);
        $this->assertStringNotContainsString("\n    <", $out);
    }

    public function test_the_sites_own_links_become_root_relative_and_others_stay(): void
    {
        $origin = request()->getSchemeAndHttpHost();
        $html = '<a href="'.$origin.'/profile/videos/5/edit">Edit</a>'
            .'<img src="'.$origin.'/storage/t.jpg" alt="">'
            .'<form action="'.$origin.'/profile/videos/5" method="POST"></form>'
            .'<button data-copy="'.$origin.'/video/x" formaction="'.$origin.'/profile/videos/mass-delete">Copy</button>'
            .'<a href="https://cdn.example.org/a.jpg">CDN</a>'
            .'<a href="'.$origin.'">Home</a>';

        $out = StudioHtml::relativeAttributes($html, StudioHtml::origins());

        $this->assertStringContainsString('<a href="/profile/videos/5/edit">', $out);
        $this->assertStringContainsString('<img src="/storage/t.jpg"', $out);
        $this->assertStringContainsString('<form action="/profile/videos/5"', $out);
        $this->assertStringContainsString('formaction="/profile/videos/mass-delete"', $out);
        // a copied address stays absolute; another origin and the bare home link are left alone
        $this->assertStringContainsString('data-copy="'.$origin.'/video/x"', $out);
        $this->assertStringContainsString('href="https://cdn.example.org/a.jpg"', $out);
        $this->assertStringContainsString('<a href="'.$origin.'">', $out);
    }

    public function test_relative_and_path_keep_the_front_controller_of_the_request(): void
    {
        $this->assertSame('/profile/videos', StudioHtml::path('user.videos.index'));
        $this->assertSame('/profile/videos/__ID__/edit', StudioHtml::pathTemplate('user.videos.edit', 'video'));
        $this->assertSame('/video/abc', StudioHtml::relative(request()->getSchemeAndHttpHost().'/video/abc'));
        $this->assertSame('https://elsewhere.test/video/abc', StudioHtml::relative('https://elsewhere.test/video/abc'));
        $this->assertNull(StudioHtml::relative(null));
    }

    public function test_count_words_gives_the_form_trans_choice_picks_for_every_count(): void
    {
        $runs = StudioHtml::countWords(fn ($n, $c) => trans_choice(':count video selected|:count videos selected', $n, ['count' => $c]), 24);

        $this->assertSame([[1, StudioHtml::MARKER.' video selected'], [2, StudioHtml::MARKER.' videos selected']], $runs);

        // Russian: one / few / many, by the language's plural rule (1, 2-4, 5-20, 21, 22-24)
        Lang::addLines(['*.:count video selected|:count videos selected' => 'Выбран :count ролик|Выбрано :count ролика|Выбрано :count роликов'], 'ru');
        app()->setLocale('ru');
        $ru = StudioHtml::countWords(fn ($n, $c) => trans_choice(':count video selected|:count videos selected', $n, ['count' => $c]), 24);
        $this->assertSame([1, 2, 5, 21, 22], array_column($ru, 0));
        $this->assertSame('Выбрано '.StudioHtml::MARKER.' роликов', $ru[2][1]);
    }
}
