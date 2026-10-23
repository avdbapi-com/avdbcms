<?php

namespace Tests\Unit;

use App\Support\TitleScript;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TitleScriptTest extends TestCase
{
    public static function titles(): array
    {
        return [
            'english' => ['Night walk through Shibuya in the rain - 4K ambience', 'latin'],
            'french accents stay latin' => ['Crème brûlée à la maison, façon chef', 'latin'],
            'german sharp s' => ['Straßenmusik in München', 'latin'],
            'romanian breve without vietnamese letters' => ['Mănăstirea și râul', 'latin'],
            'croatian d-stroke' => ['Đakovo: šetnja gradom', 'latin'],
            'vietnamese tone marks' => ['Hướng dẫn nấu phở bò tại nhà', 'vietnamese'],
            'vietnamese horned letters only' => ['Ơn trời mưa rồi', 'vietnamese'],
            'vietnamese without tone marks' => ['Đêm Sài Gòn', 'vietnamese'],
            'vietnamese uppercase' => ['NHẠC TRẺ HAY NHẤT', 'vietnamese'],
            'arabic' => ['جولة ليلية في شوارع طوكيو', 'arabic'],
            'arabic with latin brand' => ['مراجعة iPhone الجديد', 'arabic'],
            'persian uses the arabic script' => ['سفر به اصفهان', 'arabic'],
            'hindi' => ['घर पर पनीर टिक्का कैसे बनाएं', 'devanagari'],
            'thai' => ['เที่ยวกรุงเทพยามค่ำคืน', 'thai'],
            'chinese' => ['东京雨夜漫步', 'cjk'],
            'japanese kana and kanji' => ['東京の夜を歩く', 'cjk'],
            'korean with a latin tag' => ['BTS 방탄소년단 MV', 'cjk'],
            'a few kanji in a latin title stay latin' => ['Tokyo 東京 night walk in the rain', 'latin'],
            'russian' => ['Прогулка по ночной Москве', 'cyrillic'],
            'ukrainian' => ['Київ уночі', 'cyrillic'],
            'greek is other' => ['Νυχτερινή βόλτα στην Αθήνα', 'other'],
            'hebrew is other' => ['טיול לילי בתל אביב', 'other'],
            // Letters that several scripts share count for each of them (Script_Extensions, as in Video.tsx) ...
            'long vowel marks are japanese' => ['Tokyo ーーーーー night', 'cjk'],
            'katakana with long vowel marks' => ['ラーメン・ガイド2024 Tokyo ramen night', 'cjk'],
            // ... shared punctuation never does.
            'arabic commas in a latin title' => ['Hello، world، again، يا', 'latin'],
            'dandas in a mostly latin title' => ['यात्रा। travel। notes।', 'latin'],
            'cjk brackets around a latin word' => ['「Tokyo」 night', 'latin'],
            'numbers only' => ['2024 - 1080p', 'latin'],
            'emoji only' => ['🔥🔥🔥', 'latin'],
            'empty' => ['', 'latin'],
        ];
    }

    #[DataProvider('titles')]
    public function test_detects_the_title_script(string $title, string $expected): void
    {
        $this->assertSame($expected, TitleScript::detect($title));
    }

    public function test_null_is_latin(): void
    {
        $this->assertSame('latin', TitleScript::detect(null));
    }

    public function test_css_class_uses_the_title_script_prefix(): void
    {
        $this->assertSame('title-script-latin', TitleScript::cssClass('Street food tour'));
        $this->assertSame('title-script-arabic', TitleScript::cssClass('جولة ليلية'));
        $this->assertSame('title-script-cjk', TitleScript::cssClass('東京の夜'));
    }

    public function test_every_detected_value_is_a_published_script(): void
    {
        foreach (self::titles() as [$title]) {
            $this->assertContains(TitleScript::detect($title), TitleScript::SCRIPTS);
        }
    }
}
