<?php

namespace Tests\Unit;

use App\Support\CompactHtml;
use PHPUnit\Framework\TestCase;

/**
 * Round 1 (R1-069, the layout part of MEMBER_DESIGN 4.3's HTML budgets): the chrome's partials reach the browser without
 * their source indentation. A run of white space alone between two tags becomes one space (the browser renders it the
 * same); words, attribute values and the contents of script, style, pre and textarea keep every character.
 */
class CompactHtmlTest extends TestCase
{
    public function test_a_run_of_white_space_between_two_tags_becomes_one_space(): void
    {
        $html = "<ul class=\"nav\">\n        <li class=\"nav-item\">\n            <a href=\"/x\">\n                <i class=\"fa-solid fa-house\"></i>\n                <span>Home</span>\n            </a>\n        </li>\n    </ul>";

        $this->assertSame('<ul class="nav"> <li class="nav-item"> <a href="/x"> <i class="fa-solid fa-house"></i> <span>Home</span> </a> </li> </ul>', CompactHtml::compact($html));
        // one space stays one space; nothing between two tags stays nothing
        $this->assertSame('<b>a</b> <i>b</i><u>c</u>', CompactHtml::compact('<b>a</b> <i>b</i><u>c</u>'));
    }

    public function test_words_attributes_and_raw_elements_keep_their_white_space(): void
    {
        $text = "<p>  two  spaces\n  and a line  </p>";
        $this->assertSame($text, CompactHtml::compact($text), 'text with words in it');

        $attribute = "<div x-data=\"{ open: a >\n    <b }\" title=\"a  >   <b\">\n    <span>x</span>\n</div>";
        $this->assertSame("<div x-data=\"{ open: a >\n    <b }\" title=\"a  >   <b\"> <span>x</span> </div>", CompactHtml::compact($attribute), 'a ">" inside an attribute');

        $raw = "<script>\n  if (a)\n    <b>\n  x()\n</script>\n    <style>\n  a > b { }\n</style>\n  <pre>\n   <b>x</b>\n   </pre>  <textarea>\n  </textarea>";
        $this->assertSame("<script>\n  if (a)\n    <b>\n  x()\n</script> <style>\n  a > b { }\n</style> <pre>\n   <b>x</b>\n   </pre> <textarea>\n  </textarea>", CompactHtml::compact($raw));

        $comment = "<!--\n   a comment\n-->\n   <br>";
        $this->assertSame("<!--\n   a comment\n--> <br>", CompactHtml::compact($comment));
        $this->assertSame('', CompactHtml::compact(''));
        $this->assertSame("no tags  at\n all", CompactHtml::compact("no tags  at\n all"));
    }

    public function test_the_layout_compacts_its_chrome_partials_only(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/components/layout.blade.php');

        foreach (["@include('layouts.menus.header')", "@include('layouts.menus.sidebars.'.\$sidebar", "@include('layouts.menus.account')", "@include('layouts.menus.footer'"] as $include) {
            $at = strpos($layout, $include);
            $this->assertNotFalse($at, $include);
            $open = strrpos(substr($layout, 0, $at), '@php ob_start(); @endphp');
            $this->assertNotFalse($open, $include);
            $this->assertSame('', trim(substr($layout, $open + 24, $at - $open - 24)), $include.': the buffer opens right before it');
            $this->assertStringContainsString('{!! \App\Support\CompactHtml::compact((string) ob_get_clean()) !!}', substr($layout, $at, 400), $include);
        }
        // never the hands-off upload modal
        $upload = strpos($layout, "@include('users.videos.modals.upload')");
        $this->assertNotFalse($upload);
        $this->assertStringNotContainsString('ob_start', substr($layout, $upload - 120, 120));
    }
}
