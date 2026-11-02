<?php

namespace Tests\Unit;

use App\Support\ContentSafety;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * 1.2.3 (lane KS): the words that keep material out of the site (App\Support\ContentSafety). The hard list (a child or a
 * school-age minor) matches the catalogue's names of that kind, in every spelling a catalogue uses and in the other
 * languages the providers send; harmless texts of the code, adult-only words and the adult texts of real imported
 * titles never match; the default list matches non-consent, leaked / voyeur and school-age costume words; the matcher
 * works on whole words, folds case, accents, full-width letters and separators, never runs a phrase or an age across a
 * clause, takes plurals and is fast enough for a page of 10,000 items.
 */
class ContentSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ContentSafety::forget();
    }

    public function test_the_hard_list_matches_the_catalogue_names_that_present_a_minor(): void
    {
        foreach ([
            'Loli', 'Lolita', 'Rori-Kei', 'Shota', 'Lolicon', 'Shotacon',
            'JK', 'Jk', 'JK Girl', 'High School Girl', 'School Girl', 'School Girls', 'Schoolgirl', 'schoolboy',
            'Young Girl', 'Beautiful Young Girl', 'Little Boy', 'Cute Little Boy',
            'Child abuse', 'Childish Face', 'New Daughter / Wife And Child', 'Kids', 'minors', 'Underage', 'pre-teen',
            'jailbait', 'joshi kousei', 'JoshiKosei', 'joshi chuugakusei', 'shougakusei', 'randoseru', 'Kogal', 'kogyaru',
            '16 years old', '17yo', '15 y.o.', 'twelve-year-old', '16 sai', 'aged 15', 'Little sister is 14',
            // the reviewer's probe (round 2): spellings and words the first pass missed
            'underaged girl', 'schoolkids party', 'Preteens', 'highschooler', 'JK 2', 'JK2', 'Real JK vol. 3', 'jk 18',
            'Junior idol', 'U-15 gravure', 'pthc',
        ] as $text) {
            $this->assertNotNull(ContentSafety::hardMatch($text), $text.' is on the hard list');
        }
    }

    public function test_a_school_level_is_hard_with_a_person_or_as_a_whole_name_never_in_an_adults_title(): void
    {
        foreach ([
            'High School Girl', 'high school student', 'Highschool Girls', 'middle school couple', 'Junior High School Girl',
            'jr. high student', 'elementary schooler', 'kindergarten kids', 'preschool children',
            // a tag or a category named with the level alone
            'Junior high', 'HIGHSCHOOL', 'High School', 'Middle School', 'Elementary School', 'Elementary',
        ] as $text) {
            $this->assertNotNull(ContentSafety::hardMatch($text), $text.' is on the hard list');
        }

        foreach ([
            // real imported titles of adults (a sample of a site that imported AVDBAPI pages) and the reviewer's texts
            'FC2-PPV-4979299 Aspiring Elementary School Teacher. Angelic Smile, Fair Skin',
            'Her first creampie before becoming an elementary school teacher.',
            'My high school teacher seduced me', 'High school reunion of 30-year-olds', 'Kindergarten teacher roleplay',
            'Elementary, my dear Watson', 'Middle school days, my friends',
        ] as $text) {
            $this->assertNull(ContentSafety::hardMatch($text), $text.' is not on the hard list');
        }
    }

    public function test_the_hard_list_matches_the_same_words_in_the_other_languages_of_the_providers(): void
    {
        foreach ([
            // Japanese (also inside an English title that carries the original), Chinese, Thai, Korean (any ending)
            '女子高生', '女子校生デリバリー', 'MDBK-196 即尺シースルー女子校生', 'JK制服', '制服JK', 'ロリ顔美少女', 'ロリータファッション', '小学生',
            'ジュニアアイドル', '萝莉', '蘿莉', '初中生', '幼女', '正太', 'นักเรียนสาว', 'เด็กหญิง', '여고생', '여고생들', '미성년자',
            // Vietnamese, Russian (any ending), Arabic (with its article), German, Turkish, Indonesian, Spanish, Portuguese,
            // French, Filipino, Hindi
            'học sinh', 'Nữ sinh', 'trẻ em', 'bé gái', 'Школьница', 'школьницы', 'несовершеннолетняя', 'قاصر', 'الطفلة',
            'Schülerin', 'Küçük kız', 'KÜÇÜK KIZ', 'Liseli', 'Siswi SMA', 'anak kecil', 'colegiala', 'criança', 'écolière',
            'batang babae', 'नाबालिग', 'बच्ची',
            // an age under 18 with the counter of the language
            '16歳の少女', '16岁', '16살', '5 ขวบ', '16 tuổi',
        ] as $text) {
            $this->assertNotNull(ContentSafety::hardMatch($text), $text.' is on the hard list');
        }

        foreach ([
            // the words for a girl, a teen, a student or a university student, and words that only contain a hard one
            '美少女', '少女', '女学生', '学生妹', '女大学生', '正太郎', '日本男児', 'カロリー', 'テロリズムの助長', 'นักศึกษา', 'วัยรุ่น',
            '칼로리', '여대생', 'Nữ sinh viên', 'nữ sinh đại học', 'du học sinh', 'cựu học sinh', 'sinh viên', 'Детали видео',
            'девушка', 'فتاة', 'مراهقة', 'Kind of', 'Mädchen', 'küçük kız kardeş', 'Çocukluk', 'Anak Angkat', 'mahasiswa',
            'niña', 'Nina Hartley', 'menina', 'batang asawa', 'अमिताभ बच्चन', 'लड़की',
            // adult ages
            '18歳', '32歳', '19 ขวบ', '18 tuổi',
        ] as $text) {
            $this->assertNull(ContentSafety::hardMatch($text), $text.' is not on the hard list');
        }
    }

    public function test_harmless_code_texts_and_adult_only_words_never_match(): void
    {
        foreach ([
            // texts of the code (lang/en.json keys the code uses) that a simple keyword scan flags
            'Force stop all active XVIDAPI imports?', 'Force Stop All', 'Force HTTPS Redirect', 'Force Verify Email',
            'Abuse limits', 'Grape', 'Sleeping Bag',
            'Promotes terrorism', 'Sorority', 'Another step of the update is running. Wait a moment.', 'Minify JS',
            'Club Display: Syne + Be Vietnam Pro',
            // adult-only words (the brief): stay out of both lists
            'Teen (18+)', 'Teen', 'Teens', 'Cute Teen', '18 Years Old', '19 Years Old', '18yo', '118 years old', '19yo amateur',
            'University Student', 'Female College Student', 'Childhood Friend', 'Childhood', 'Young Wife', 'Young Woman',
            'Petite', 'Flat Chest', 'Virgin', 'Student', 'School', 'Uniform', 'Swimsuit', 'Sailor', 'Femdom Slave', 'Torture',
            'Humiliation', 'Toilet', 'Drugs And Aphrodisiacs', 'Gangster', 'Gangbang', 'minority', 'kidnap', 'Shot a video',
            'Leaking', 'Peep Show', 'Drapes', 'Kidney stones', 'Minority report', 'Grape juice',
        ] as $text) {
            $this->assertNull(ContentSafety::hardMatch($text), $text.' is not on the hard list');
            $this->assertNull(ContentSafety::siteMatch($text, ContentSafety::DEFAULT_WORDS), $text.' is not on the default list');
        }

        // A text of the code that says "forced": the default list matches the word (it would block an item named so),
        // the hard list does not; the language files keep it because the code uses it (never a video's title).
        $this->assertSame('forced', ContentSafety::siteMatch('Forced session.secure=true because the request / APP_URL is HTTPS.', ContentSafety::DEFAULT_WORDS));
        $this->assertNull(ContentSafety::hardMatch('Forced session.secure=true because the request / APP_URL is HTTPS.'));
        // the report reason members choose for a video: a text of the code (App\Enums\ReportReason), never a catalogue name
        $this->assertSame('child', ContentSafety::hardMatch(__('Child abuse')));
    }

    public function test_adult_texts_that_only_look_like_a_hard_word_never_match(): void
    {
        foreach ([
            // an adjective, names, a toy, a pregnant woman, initials, a genius, an adult's age in words
            'A minor detail', 'minor league', 'Billy the Kid western parody', 'Kid Rock', 'Pregnant with child',
            'Mary J. K. Smith', 'Top 5 yo-yo tricks', 'Kaguya-sama wa Kokurasetai? Tensai-tachi no Ren ai Zunousen',
            'VENU-039 Thirty Two Years Old - Nakamori Reiko 32歳', 'she is 5 feet tall', 'she is 18', 'he was 2 hours late',
            // a phrase or an age never runs across a clause
            'After 10 years, old flames meet again', 'Part 2, Sai in Tokyo', 'Little, girl',
            // product codes
            'JK-123', 'JK123', 'JKS-001', 'JKT48 concert',
        ] as $text) {
            $this->assertNull(ContentSafety::hardMatch($text), $text.' is not on the hard list');
        }
    }

    public function test_the_default_list_matches_non_consent_leaked_voyeur_and_school_costume_words(): void
    {
        foreach ([
            'Rape', 'Gang Rape', 'raped', 'Forced Blowjob', 'Molester', 'Female Molester', 'Chikan', 'Groper', 'Sexual Slavery',
            'sex slaves', 'Hypnosis', 'Hypnotic Brainwashing', 'drugged', 'unconscious', 'Drunk', 'Drunken Intoxication',
            'Confinement', 'Uncensored Leak', 'Uncensored Leaked', 'Voyeur', 'Voyeur / Peeping', 'Voyeurism', 'Hidden Cams',
            'Spycam', 'spy camera', 'Upskirt', 'up-skirt', 'School Uniform', 'School Swimsuit', 'Swimwear / School Swimwear',
            'Competitive Swimming/School Swimsuits', 'School-themed', 'School Stuff', 'Student Girl', 'Honor Student',
            'Sailor Suit', 'Bloomer', 'Gym Clothes/Bloomers', 'buruma',
            // the second pass's words (the reviewer's probe and the catalogue): school clubs, loose socks, the girl
            // guides, the bratty girl, and the baby face (moved from the hard list: an adult's young face)
            'Loose Socks', 'Club Activities&Manager', 'Club Activity Manager', 'Club/Manager', 'Girl Guide', 'Girl Scouts',
            'Female brat', 'Mesugaki', 'Baby Face', 'Baby face MILF', 'Too cute baby face young wife, 26 years old',
        ] as $text) {
            $this->assertNotNull(ContentSafety::siteMatch($text, ContentSafety::DEFAULT_WORDS), $text.' is on the default list');
            $this->assertNull(ContentSafety::hardMatch($text), $text.' is not on the hard list');
        }
    }

    public function test_case_accents_full_width_letters_and_separators_are_folded(): void
    {
        $this->assertSame('j k', ContentSafety::hardMatch('J.K. Girl'));
        $this->assertSame('jk', ContentSafety::hardMatch("\u{FF2A}\u{FF2B}"), 'full-width JK');
        $this->assertSame('lolita', ContentSafety::hardMatch("Lol\u{00ED}ta"), 'an accent');
        $this->assertSame('school girl', ContentSafety::hardMatch('School_Girl'));
        $this->assertSame('school girl', ContentSafety::hardMatch('school-girl'));
        $this->assertSame('highschool girl', ContentSafety::hardMatch('HIGHSCHOOL GIRL'));
        $this->assertSame('loli', ContentSafety::hardMatch('Loli &amp; more'), 'an HTML entity');
        $this->assertSame('school girls', ContentSafety::hardMatch('School  Girls!!'));
        $this->assertSame("a b\nc d", ContentSafety::normalize("A-B\r\nC_D"), 'line breaks stay, other separators are one space');
        $this->assertStringNotContainsString('years old', ContentSafety::normalize('ten years, old'), 'a comma ends a clause');
    }

    public function test_plurals_and_possessives_match_but_never_a_word_inside_another(): void
    {
        $this->assertSame('kids', ContentSafety::hardMatch('Kids'));
        $this->assertSame('kid', ContentSafety::hardMatch("kid's room"));
        $this->assertSame('lolis', ContentSafety::hardMatch('lolis'));
        $this->assertNull(ContentSafety::hardMatch('kidney'));
        $this->assertNull(ContentSafety::hardMatch('Minority Report'));
        $this->assertNull(ContentSafety::hardMatch('childhood memories'));
        $this->assertSame('voyeurs', ContentSafety::siteMatch('voyeurs', ContentSafety::DEFAULT_WORDS));
        $this->assertNull(ContentSafety::siteMatch('grapes', ContentSafety::DEFAULT_WORDS));
    }

    public function test_a_two_letter_abbreviation_is_never_a_product_code(): void
    {
        $this->assertNull(ContentSafety::hardMatch('JK-123'));
        $this->assertNull(ContentSafety::hardMatch('JK123'));
        $this->assertNotNull(ContentSafety::hardMatch('jk 18'), 'a short number is no product code (JK, 18)');
        $this->assertNotNull(ContentSafety::hardMatch('JK 2'), 'a part of a series');
        $this->assertNotNull(ContentSafety::hardMatch('jk uniform'));
        $this->assertNull(ContentSafety::hardMatch('JKT48'));
    }

    public function test_an_item_is_checked_field_by_field(): void
    {
        $matcher = ContentSafety::withWords(ContentSafety::DEFAULT_WORDS);

        // two tags never make a phrase of the list together
        $this->assertNull($matcher->check(['A clean title', ['School', 'Girl'], 'Censored']));
        $this->assertSame('hard', $matcher->check(['A clean title', ['Drama', 'School Girl'], 'Censored']));
        $this->assertSame('hard', $matcher->check(['A clean title', [], 'Lolita']), 'a category');
        $this->assertSame('hard', $matcher->check(['A clean title', ['Drama', 'Junior High'], 'Censored']), 'a tag named with a level alone');
        $this->assertNull($matcher->check(['My high school teacher', ['Drama'], 'Censored']), 'a level in a title');
        $this->assertSame('site', $matcher->check(['A clean title', ['Voyeur'], 'Censored']));
        $this->assertSame('hard', $matcher->check(['Voyeur', 'Loli']), 'the hard list is answered first');
        $this->assertNull($matcher->check(['', null, [], ['']]));
        $this->assertTrue($matcher->blocks('Gang Rape'));
        $this->assertFalse($matcher->blocks('Gangbang'));
        $this->assertSame(count(ContentSafety::DEFAULT_WORDS), $matcher->siteWordCount());
    }

    public function test_the_owners_list_replaces_the_default_list_but_never_the_hard_list(): void
    {
        $empty = ContentSafety::withWords([]);
        $this->assertNull($empty->check(['Voyeur', 'Rape']), 'an empty list of the owner: the default words are allowed');
        $this->assertSame('hard', $empty->check(['Schoolgirl']), 'the hard list is always on');
        $this->assertSame(0, $empty->siteWordCount());

        $own = ContentSafety::withWords(ContentSafety::parseWords("Cosplay\nnurse uniform"));
        $this->assertSame('site', $own->check(['Cosplay Party']));
        $this->assertSame('site', $own->check(['Nurse-Uniform']));
        $this->assertNull($own->check(['Voyeur']));
    }

    public function test_the_owners_words_of_other_scripts_match_inside_their_texts(): void
    {
        // Chinese, Japanese and Thai have no spaces between words: the owner's word matches anywhere; a Korean word
        // with any ending
        $own = ContentSafety::withWords(ContentSafety::parseWords("强奸\n盗撮\n강간"));
        $this->assertSame('site', $own->check(['他被强奸了']), 'inside a Chinese title');
        $this->assertSame('site', $own->check(['温泉盗撮シリーズ']), 'inside a Japanese title');
        $this->assertSame('site', $own->check(['강간범']), 'a Korean word with an ending');
        $this->assertNull($own->check(['A clean title']));
    }

    public function test_the_words_of_a_text_box(): void
    {
        $this->assertSame(['rape', 'Voyeur', 'hidden cam', 'nurse uniform'], ContentSafety::parseWords(" rape \n\nVoyeur, hidden cam;\r\nRAPE\nx\nof\n nurse uniform "));
        $this->assertSame([], ContentSafety::parseWords(''));
        $this->assertSame([], ContentSafety::parseWords(null));
        $this->assertSame(['盗撮', '강간'], ContentSafety::parseWords("盗撮\n강간\n乳"), 'two signs are a word in Chinese, Japanese or Korean, one is not');
        $this->assertCount(ContentSafety::MAX_WORDS, ContentSafety::parseWords(implode("\n", array_map(fn (int $i): string => 'word'.$i, range(1, 700)))));
        $this->assertSame(ContentSafety::MAX_WORD_LENGTH, mb_strlen(ContentSafety::parseWords(str_repeat('a', 300))[0]));
        // the words of an owner never break the pattern (they are matched as text)
        $this->assertSame('site', ContentSafety::withWords(ContentSafety::parseWords("a+b (c)\n.*"))->check(['A B C']));
        $this->assertSame(implode("\n", ContentSafety::DEFAULT_WORDS), ContentSafety::defaultText());
        $this->assertSame(ContentSafety::defaultText(), config('content_safety.default_words'), 'the settings form shows this list');
    }

    public function test_the_save_says_what_is_wrong_with_a_list(): void
    {
        $this->assertSame([], ContentSafety::problems(ContentSafety::defaultText()), 'the default list is fine');
        $this->assertSame([], ContentSafety::problems(''));

        $short = ContentSafety::problems("rape\nof\nin\n盗撮");
        $this->assertCount(1, $short);
        $this->assertStringContainsString('of, in', $short[0]);

        $long = ContentSafety::problems("rape\n".str_repeat('x', 101));
        $this->assertCount(1, $long);
        $this->assertStringContainsString('100', $long[0]);

        $many = ContentSafety::problems(implode("\n", array_map(fn (int $i): string => 'word'.$i, range(1, 501))));
        $this->assertCount(1, $many);
        $this->assertStringContainsString('501', $many[0]);
    }

    public function test_names_the_hard_list_uses_are_hidden_and_tags_are_filtered(): void
    {
        $this->assertTrue(ContentSafety::hides('Lolita'));
        $this->assertTrue(ContentSafety::hides('  High School Girl '));
        $this->assertTrue(ContentSafety::hides('Nữ sinh'), 'a tag a localised page named');
        $this->assertFalse(ContentSafety::hides('Voyeur'), 'only the hard list hides names');
        $this->assertFalse(ContentSafety::hides(''));
        $this->assertFalse(ContentSafety::hides(null));

        $tags = new Collection([(object) ['name' => 'Drama'], (object) ['name' => 'Loli'], (object) ['name' => 'Voyeur']]);
        $this->assertSame(['Drama', 'Voyeur'], ContentSafety::visibleTags($tags)->pluck('name')->all());

        // categories: filtered after the list was read (no query of its own); a form may keep one (the video's own)
        $categories = new Collection([(object) ['id' => 1, 'title' => 'Drama'], (object) ['id' => 2, 'title' => 'Schoolgirl Club'], (object) ['id' => 3, 'title' => 'Uncensored Leaked']]);
        $this->assertSame(['Drama', 'Uncensored Leaked'], ContentSafety::visibleCategories($categories)->pluck('title')->all());
        $this->assertSame([1, 2, 3], ContentSafety::visibleCategories($categories, 2)->pluck('id')->all());
        $this->assertSame([1, 3], ContentSafety::visibleCategories($categories, 3)->pluck('id')->all());
    }

    public function test_a_page_of_10000_items_is_checked_in_well_under_a_second(): void
    {
        $matcher = ContentSafety::withWords(ContentSafety::DEFAULT_WORDS);
        $items = [];

        for ($i = 0; $i < 10000; $i++) {
            $items[] = ['A long imported title number '.$i.' with performer and studio names', ['Big Tits', 'Creampie', 'Featured', 'Drama',
                'Married Woman', 'Solo Work', 'Hi-Def', 'Cosplay', 'Beautiful Girl', 'Slender', 'Amateur '.$i], 'Censored', 'Japanese'];
        }

        $started = microtime(true);
        $blocked = 0;

        foreach ($items as $fields) {
            $blocked += $matcher->check($fields) === null ? 0 : 1;
        }

        $this->assertSame(0, $blocked);
        $this->assertLessThan(3.0, microtime(true) - $started, '10,000 items (measured: about 0.2 s)');
    }
}
