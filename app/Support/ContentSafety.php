<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Collection;
use Normalizer;
use Throwable;

/**
 * The words that keep material out of the site (1.2.3): one place for the rules the importers, the update to 1.2.3 and
 * the public lists use.
 *
 * Two lists:
 *  - The hard list: words and phrases that present a person as a child or a school-age minor in a sexual catalogue, in
 *    English and in the romanised Japanese the AVDBAPI / XVIDAPI catalogues use (HARD), a school level written with a
 *    person (HARD_SCHOOL_LEVELS + HARD_SCHOOL_PERSONS: a high school girl, never a high school teacher), the same
 *    words in the other languages the providers send titles and tags in (HARD_OTHER_LANGUAGES: XVIDAPI keeps every
 *    title in its original language, AVDBAPI pages can be fetched in another language and its English titles carry the
 *    Japanese original), and an age under 18 written out (HARD_AGE). Fixed here, never a setting and never shown in the
 *    admin: an item that uses one is never imported, the update hides the videos that use one (App\Support\
 *    BlockedWordsPass), and a tag or a category named with one is never shown to visitors (visibleCategories(),
 *    visibleTags()), nor an actor, director or studio named with one (visibleNames(); no creator channel for them
 *    either: hardMatchAny()). Adult-only words stay out (teen 18+, 18 or 19 years old, university and college students), and so
 *    does childhood friend (two adults who grew up together): the matcher only matches whole words, so child never
 *    matches childhood.
 *    (These comments never quote a catalogue text as it is: the release's translation merge counts a quoted text
 *    in a source file as a use of that translation key.)
 *  - DEFAULT_WORDS: sex without consent, leaked and voyeur recordings, and school-age costume play. The site setting
 *    import_blocked_words (one word or phrase per line, edited on Content > Import) starts as this list, and the site
 *    owner decides for their site; only new imports read it (the update leaves existing videos that use only these
 *    words as they are).
 * Why each word is in (and why near words are out) is noted next to it and in the lane notes of 1.2.3 (lane KS).
 *
 * The matcher: case-insensitive and Unicode-aware; full-width letters, ligatures and accents count as their plain
 * letters (NFKC, then the accents dropped); every run of punctuation or separators counts as one space (J.K.,
 * school-girl, School_Girl), except that a comma, a semicolon, a colon, an exclamation or question mark and a full stop
 * before a space end a clause: no phrase and no age runs across one (after ten years, old flames is no age). The words
 * of a phrase may also be written together (schoolgirl); a final s or es (plurals, possessives) still matches; only whole
 * words match, so the texts force stop, sleeping bag, grape or childhood never match the words forced, sleeping, rape or
 * child. A Latin word next to Chinese or Japanese letters is a whole word too (JK in a Japanese title). A two-letter
 * abbreviation also matches with a dot or a space between its letters (J.K.), and before a short number (a part of a
 * series), never before a product code of three or more digits (JK-123). Words of scripts written without spaces
 * (Chinese, Japanese, Thai) and Arabic words (written with their articles and pronouns) match anywhere in a text;
 * Korean, Russian, German and Turkish word starts match whatever ending the word has.
 * One compiled pattern per list, and the fields of an item are matched in one call, so a page of 10,000 items takes a
 * fraction of a second.
 */
final class ContentSafety
{
    /** The site setting with the owner's words (group uploads; Content > Import > Blocked words). */
    public const SETTING = 'import_blocked_words';

    public const SETTING_GROUP = 'uploads';

    /** A word of the owner's list needs this many letters or digits (two letters, of or in, would block nearly every title). */
    public const MIN_LENGTH = 3;

    /** ... or this many in a script written without spaces or in Korean (a word is often two signs there). */
    public const MIN_LENGTH_DENSE = 2;

    /** At most this many words of the owner's list, each of at most MAX_WORD_LENGTH characters (the save refuses more). */
    public const MAX_WORDS = 500;

    public const MAX_WORD_LENGTH = 100;

    /** The reason a run log, the update and the admin give for an item or a video these words keep out. */
    public const REASON = 'blocked words';

    /**
     * The hard list in English and romanised Japanese, matched as whole words (normalised spelling: lower case, words
     * separated by one space). Decided from the catalogue of lang/en.json (a review of every text in it),
     * the brief of 1.2.3, the reviewer's probe and real imported titles (lane KS notes).
     */
    public const HARD = [
        // A child in general (child also takes a catalogue tag that names a wife and child: a child named in a sexual
        // catalogue); childish takes the child-face tag the brief names.
        'child', 'children', 'childish', 'school child', 'school children', 'school kid', 'kid', 'kiddie', 'kiddy',
        'toddler', 'infant', 'little girl', 'little boy', 'young girl', 'young boy',
        // Words for a person under the age of consent, and the words of those who abuse children.
        'minor', 'under age', 'under aged', 'pre teen', 'tween', 'jailbait',
        'pedophile', 'paedophile', 'pedophilia', 'paedophilia', 'pthc',
        // Loli / shota: Japanese words for a child in a sexual sense, and their romanised spellings (rori, rori-kei). Shota
        // is also a Japanese given name: a catalogue that names a man so in a title loses that item (accepted).
        'loli', 'lolita', 'lolicon', 'lolikon', 'rori', 'rorikon', 'shota', 'shotacon', 'shotakon',
        // School-age minors: school girls and boys, the pupils of the school levels before university (a level alone is
        // no person: a teacher of that level is an adult, HARD_SCHOOL_LEVELS), the Japanese words for the girl students
        // of those levels (JK = joshi kousei, a high school girl; kogal / kogyaru, the high school gal) and an elementary
        // school pupil's backpack (randoseru).
        'school girl', 'school boy', 'high schooler', 'middle schooler', 'grade schooler', 'elementary schooler',
        'kindergartener', 'kindergartner', 'preschooler',
        'jk', 'joshi kosei', 'joshi kousei', 'kokosei', 'koukousei', 'joshi chugakusei', 'joshi chuugakusei', 'chugakusei',
        'chuugakusei', 'joshi shogakusei', 'joshi shougakusei', 'shogakusei', 'shougakusei', 'kogal', 'kogyaru',
        'randoseru',
        // Child gravure models: the junior idol (U-12, U-15 in its titles).
        'junior idol', 'u 12', 'u 15',
    ];

    /**
     * The school levels before university: blocked with a person of that level (HARD_SCHOOL_PERSONS) after them, or as a
     * whole field (a tag or a category named High School, Junior High); never in a title such as a high school teacher's
     * or a reunion's.
     */
    public const HARD_SCHOOL_LEVELS = [
        'high school', 'junior high', 'junior high school', 'jr high', 'jr high school', 'middle school', 'elementary',
        'elementary school', 'primary school', 'grade school', 'kindergarten', 'preschool', 'nursery school',
    ];

    /** The persons of a school level (a high school girl, an elementary school pupil, a middle school couple). */
    public const HARD_SCHOOL_PERSONS = [
        'girl', 'boy', 'student', 'pupil', 'kid', 'child', 'children', 'teen', 'teenager', 'freshman', 'freshmen',
        'sophomore', 'junior', 'senior', 'gal', 'cheerleader', 'couple', 'daughter', 'son', 'sister', 'brother', 'virgin',
        'lolita', 'idol', 'beauty', 'babe', 'cutie', 'chick', 'slut', 'whore', 'bitch',
    ];

    /**
     * The hard list in the other languages of the providers and of the site (the words a translation uses for a child,
     * a minor or a school pupil; never the words for a teen, a girl or a student, which are adult words in these
     * catalogues as they are in English). 'words': whole words; 'starts': the start of a word, any ending (languages
     * that inflect their words); 'anywhere': anywhere in a text (scripts without spaces, Arabic with its articles).
     * Written as the language writes them; matched in the normalised spelling (accents dropped).
     */
    public const HARD_OTHER_LANGUAGES = [
        // Chinese (simplified and traditional): loli, little girl / boy, child, the school levels' pupils, minor, shota,
        // a child prostitute. 学生 / 女学生 / 少女 (student, girl) stay out like their English words.
        'zh' => ['anywhere' => ['萝莉', '蘿莉', '幼女', '幼齿', '幼齒', '幼童', '女童', '男童', '儿童', '兒童', '小学生', '小學生',
            '初中生', '中学生', '中學生', '高中生', '未成年', '正太', '雏妓', '雛妓', '小女孩', '小男孩']],
        // Japanese: loli (these words, and compileHard()'s rule for the katakana before any other word: alone it is also
        // inside katakana words such as calorie or terrorism), shota, little girl, girl / boy child,
        // child, kindergarten and small children, the school levels' pupils (high school girl, girl's school pupil),
        // minor, the elementary school backpack. 少女 / 美少女 / 生徒 / 学生 (girl, beautiful girl, pupil, student) and the
        // Japanese idiom for a man (日本男児) stay out.
        'ja' => ['anywhere' => ['ロリータ', 'ロリコン', 'ロリっ子', 'ロリ系', 'ロリ顔', 'ロリ巨乳', 'ロリ体型', 'ロリ美少女', 'ロリ娘',
            'ショタ', '女児', '児童', '園児', '幼児', '高校生', '女子高生', '女子校生', '中高生', 'ランドセル', 'ジュニアアイドル']],
        // Thai: school pupil (นักเรียน; a university student is another word), girl / boy child, minor, loli, a primary
        // school child, and the explicit word for a child's body.
        'th' => ['anywhere' => ['นักเรียน', 'เด็กหญิง', 'เด็กชาย', 'ผู้เยาว์', 'โลลิ', 'เด็กประถม', 'หีเด็ก']],
        // Arabic: minor, child / children, schoolgirl / pupils, a school student, Lolita.
        'ar' => ['anywhere' => ['قاصر', 'طفل', 'أطفال', 'تلميذة', 'تلاميذ', 'طالبة مدرسة', 'لوليتا']],
        // Korean: loli, shota, minor, the pupils of each school level (and their slang words), girl student (a school
        // girl: a university student is another word), child, children.
        'ko' => ['starts' => ['로리', '쇼타', '미성년', '여고생', '여중생', '여중학생', '초등학생', '초딩', '여초딩', '중학생', '중딩', '여중딩',
            '고등학생', '고딩', '여고딩', '여학생', '아동', '어린이']],
        // Russian: schoolgirl / schoolboy, minor (two words), loli, child, children's; children as whole words (детали,
        // details, starts the same way).
        'ru' => ['starts' => ['школьниц', 'школьник', 'несовершеннолетн', 'малолетк', 'малолетн', 'лоли', 'ребен', 'детск'],
            'words' => ['дети', 'детей', 'детям', 'детьми', 'детях']],
        // German: minor, schoolgirl, girl pupil, child pornography. Kind (child) stays out: it is an English word too.
        'de' => ['starts' => ['minderjährig', 'schulmädchen', 'schülerin', 'kinderporn']],
        // Turkish: high schooler (any ending), under age, little girl (not little sister), child, children.
        'tr' => ['starts' => ['liseli'], 'words' => ['reşit olmayan', 'küçük kız', 'çocuk', 'çocuklar']],
        // Vietnamese: school pupil (not a student abroad, not a former pupil), schoolgirl (not a university girl
        // student), children, little girl / boy, minor, kids.
        'vi' => ['words' => ['học sinh', 'nữ sinh', 'trẻ em', 'trẻ con', 'bé gái', 'bé trai', 'vị thành niên', 'con nít']],
        // Indonesian and Malay: under age, school child, the pupils of the school levels, little child / girl, school girl,
        // the senior and junior high school levels.
        'id' => ['words' => ['bawah umur', 'anak sekolah', 'anak sma', 'anak smp', 'anak sd', 'anak kecil', 'gadis kecil',
            'gadis sekolah', 'budak sekolah', 'pelajar sekolah', 'sma', 'smp']],
        // Spanish, Portuguese, French, Filipino and Hindi: minor, schoolgirl, child, children's, nymphet, little girl,
        // girl / boy child, a young adolescent girl. Niña / niño stay out (Nina is a name once the accent is folded).
        'es' => ['words' => ['menor de edad', 'menores de edad', 'colegiala', 'infantil', 'pedofilia']],
        'pt' => ['words' => ['menor de idade', 'menores de idade', 'criança', 'colegial', 'colegiais', 'ninfeta']],
        'fr' => ['words' => ['écolière', 'collégienne', 'lycéenne', 'fillette', 'mineure', 'enfant']],
        'fil' => ['words' => ['batang babae', 'batang lalaki', 'dalagita']],
        'hi' => ['words' => ['नाबालिग', 'बच्ची', 'बच्चा', 'बच्चे']],
    ];

    /**
     * Uses of a word of the lists that are no child (normalised spelling: the text before, the text after): an adjective
     * (a minor detail, minor league), names (Billy the Kid, The Karate Kid, Kid Rock, Kid Cudi), kid gloves, a student
     * abroad or a former pupil and a university girl student in Vietnamese, the little sister in Turkish, a name in
     * Chinese.
     */
    private const EXCEPT = [
        // pregnant with child: a pregnant woman
        'child' => ['(?<!pregnant with )(?<!great with )', ''],
        'minor' => ['', '(?! (?:detail|league|key|chord|scale|role|change|issue|problem|injury|injuries|edit|update|fix|bug|flaw|planet|surgery|character|part|setback|offen[cs]e)(?:e?s)?(?![\p{L}\p{N}]))'],
        'kid' => ['(?<!billy the )(?<!karate )', '(?! (?:rock|cudi|gloves?)(?![\p{L}\p{N}]))'],
        'học sinh' => ['(?<!du )(?<!cuu )(?<!dai )(?<!đai )', '(?! vien)'],
        'nữ sinh' => ['', '(?! vien)(?! [dđ]ai hoc)'],
        'küçük kız' => ['', '(?! kardes)'],
        '正太' => ['', '(?!郎)'],
    ];

    /**
     * An age under 18 written out (normalised spelling), matched as whole words: "16 years old", "15 yo", "17yo",
     * "twelve-year-old", "16 sai" (romanised Japanese, digits only: tensai is a genius), "aged 15", "my sister is 14"
     * (a person, then is / was / turned and the age, not a size or a time), "16 tuổi" (Vietnamese). 18 and 19 stay out
     * (18 years old is an adult genre); a number word after twenty, thirty ... is part of an adult age (thirty two years
     * old), and a yo-yo is a toy.
     */
    public const HARD_AGE = [
        // (the number word is looked for first: the test of the words before it only runs where one is)
        '(?=(?:one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen)[ ](?:years?[ ]?old|yrs?[ ]?old|y[ ]?o))(?<!twenty )(?<!thirty )(?<!forty )(?<!fifty )(?<!sixty )(?<!seventy )(?<!eighty )(?<!ninety )(?:one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen)[ ](?:years?[ ]?old|yrs?[ ]?old|y[ ]?o(?![ ]yo))',
        '(?:1[0-7]|[1-9])[ ]?(?:years?[ ]?old|yrs?[ ]?old|y[ ]?o(?![ ]yo)|sai|tuoi)',
        'aged[ ](?:1[0-7]|[1-9]|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen)',
        '(?:she|he|girl|boy|sister|brother|daughter|son|kid|child)[ ](?:is|was|turned|turns)[ ](?:only[ ]|just[ ])?(?:1[0-7]|[1-9]|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen)(?![ ](?:feet|foot|ft|inch|inches|cm|mm|m|kg|lbs?|pounds?|minutes?|mins?|hours?|hrs?|seconds?|secs?|times|days?|weeks?|months?|percent|centimeters?|meters?|metres?|cups?)(?![\p{L}\p{N}]))',
    ];

    /** The letters each pattern of HARD_AGE may start with (the pattern is only tried where one of them is). */
    private const HARD_AGE_FIRSTS = ['otfsen', '1-9', 'a', 'shgbdkc'];

    /** An age under 18 with the counter of Chinese, Japanese, Korean and Thai (16歳, 16岁, 16살; ขวบ counts a child's years). */
    private const HARD_AGE_COUNTERS = ['歳', '才', '岁', '歲', '살', 'ขวบ'];

    /**
     * The default list of the site setting import_blocked_words, as the owner sees it (one per line). Out on purpose:
     * force (it is in the force stop buttons too) and sleeping (the brief), slave alone (a consensual BDSM role; sex
     * slave and sexual slavery are in), torture, humiliation and punishment (consensual BDSM), toilet (not a recording
     * made in secret; those are named voyeur, hidden camera or spy cam), drugs and aphrodisiacs (an aphrodisiac taken by
     * choice), gym clothes (also adult sports wear) and the incest group (not part of these lists).
     */
    public const DEFAULT_WORDS = [
        // Sex without consent: force, molesting (chikan and groper are its Japanese and English names), slavery,
        // hypnosis and brainwashing, a person drugged, unconscious or drunk (who cannot consent), sexual abuse and
        // confinement (kankin, holding a person captive).
        'rape', 'raped', 'raping', 'rapist', 'forced',
        'molest', 'molested', 'molester', 'molesting', 'molestation', 'chikan', 'grope', 'groped', 'groper', 'groping',
        'sex slave', 'sexual slavery', 'hypnosis', 'hypnotic', 'hypnotised', 'hypnotized', 'brainwashed', 'brainwashing',
        'drugged', 'unconscious', 'drunk', 'drunken', 'sexual abuse', 'sexually abused', 'confinement',
        // Leaked and voyeur recordings.
        // ("up-skirt" also takes "upskirt" and "up skirt", "spy cam" also "spycam": the words of a phrase may be together)
        'leak', 'leaked', 'voyeur', 'voyeurism', 'peeping', 'hidden cam', 'hidden camera', 'spy cam', 'spy camera', 'up-skirt',
        // School-age costume play and child-like looks: the school uniform and swimsuit, the sailor suit (the Japanese
        // school uniform), the bloomers of the Japanese school gym uniform (buruma), the loose socks of the high school gal,
        // the school clubs and their manager (bukatsu), the uniform of the girl guides and scouts (children's groups),
        // school-themed titles (School Stuff is the same Japanese genre), the girl students of those titles, the bratty
        // girl (mesugaki) and the baby face (dougan: an adult's young face, the same genre as the child-face tag of the
        // hard list; the owner may allow it).
        'school uniform', 'school swimsuit', 'school swimwear', 'school-themed', 'school stuff', 'student girl',
        'honor student', 'sailor suit', 'sailor uniform', 'bloomer', 'buruma', 'loose socks', 'club activity',
        'club activities', 'club manager', 'girl guide', 'girl scout', 'mesugaki', 'female brat', 'baby face', 'baby faced',
    ];

    /** Letters of the scripts written without spaces between words (their words match anywhere in a text). */
    private const DENSE = '\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}';

    /**
     * The end of a clause in a normalised text (a comma, a full stop before a space ...): no phrase and no age runs
     * across it, and a name is no whole field when a clause of it ends (a line break ends a field).
     */
    private const CLAUSE = "\x1F";

    /** @var array<string, string> compiled patterns, by the hash of their word list */
    private static array $patterns = [];

    /** @var array<string, bool> names already asked for hides() in this process (bounded; the hard list is fixed) */
    private static array $hidden = [];

    /**
     * @param  string  $hard  the compiled hard list
     * @param  string|null  $site  the compiled site list (null: no word of its own)
     * @param  int  $siteWords  how many words the site list holds
     */
    private function __construct(private readonly string $hard, private readonly ?string $site, private readonly int $siteWords)
    {
    }

    /** The matcher of one import run: the hard list and the site's list as it is when the run starts. */
    public static function forImports(): self
    {
        return self::withWords(self::siteWords());
    }

    /** A matcher with the hard list and these words (tests; the owner's list in forImports()). */
    public static function withWords(array $words): self
    {
        $site = self::compile($words);

        return new self(self::hardPattern(), $site, $site === null ? 0 : count($words));
    }

    /**
     * Whether an item with these fields (its title, its tags, its categories) uses a word of either list: 'hard', 'site'
     * or null. The fields are matched each on its own (a phrase never runs from one tag into the next).
     *
     * @param  iterable<mixed>  $fields
     */
    public function check(iterable $fields): ?string
    {
        $text = self::text($fields);

        if ($text === '') {
            return null;
        }

        if (preg_match($this->hard, $text) === 1) {
            return 'hard';
        }

        return $this->site !== null && preg_match($this->site, $text) === 1 ? 'site' : null;
    }

    /** Whether a single name (a tag, a category) uses a word of either list. */
    public function blocks(?string $name): bool
    {
        return $name !== null && $name !== '' && $this->check([$name]) !== null;
    }

    /** How many words of the owner's list this matcher uses (the run log says so). */
    public function siteWordCount(): int
    {
        return $this->siteWords;
    }

    /** The word of the hard list $text uses (normalised), or null. */
    public static function hardMatch(?string $text): ?string
    {
        return self::firstMatch(self::hardPattern(), $text);
    }

    /**
     * The word of the hard list the first of these texts uses (normalised), or null. The importers ask it before they
     * make or reuse a creator channel from a studio, director or actor name (the name, the handle and the description
     * the channel gets), and the update's pass over the channels they made before 1.2.3's refresh asks it for each
     * channel's stored texts (App\Support\BlockedCreatorsPass). Only the hard list: the site's own words keep items
     * out, never a person or a studio.
     *
     * @param  iterable<mixed>  $texts
     */
    public static function hardMatchAny(iterable $texts): ?string
    {
        foreach ($texts as $text) {
            $word = self::hardMatch(is_string($text) || $text instanceof \Stringable ? (string) $text : null);

            if ($word !== null) {
                return $word;
            }
        }

        return null;
    }

    /** The word of $words (the owner's list when null) $text uses (normalised), or null. */
    public static function siteMatch(?string $text, ?array $words = null): ?string
    {
        $pattern = self::compile($words ?? self::siteWords());

        return $pattern === null ? null : self::firstMatch($pattern, $text);
    }

    /**
     * Whether visitors never see this tag or category name: it uses a word of the hard list. Only the hard list hides
     * names (the owner's list only keeps new imports out).
     */
    public static function hides(?string $name): bool
    {
        $name = trim((string) $name);

        if ($name === '') {
            return false;
        }

        if (array_key_exists($name, self::$hidden)) {
            return self::$hidden[$name];
        }

        if (count(self::$hidden) >= 5000) {
            self::$hidden = [];
        }

        return self::$hidden[$name] = self::hardMatch($name) !== null;
    }

    /**
     * The tags visitors may see: those whose name uses a word of the hard list are left out.
     *
     * @template T of Collection
     *
     * @param  T  $tags
     * @return T
     */
    public static function visibleTags(Collection $tags): Collection
    {
        return $tags->reject(fn ($tag): bool => self::hides(is_object($tag) ? (string) ($tag->name ?? '') : (string) $tag))->values();
    }

    /**
     * The categories visitors may see: those whose name uses a word of the hard list are left out. Filtered after the
     * list was read (every public list of categories reads its rows anyway), so it costs no query of its own and a page
     * or a menu runs the queries it ran before 1.2.3. The admin keeps every category; renaming one shows it again.
     *
     * @template T of Collection
     *
     * @param  T  $categories  rows with a title (models or plain rows)
     * @param  int|null  $keep  the id of a category kept anyway (the studio's form keeps the video's own, so an unchanged
     *                          form still saves)
     * @return T
     */
    public static function visibleCategories(Collection $categories, ?int $keep = null): Collection
    {
        return $categories->reject(fn ($category): bool => is_object($category)
            && ($keep === null || (int) ($category->id ?? 0) !== $keep)
            && self::hides((string) ($category->title ?? '')))->values();
    }

    /**
     * The people and studio names visitors may see of a catalogue field (an import's actors, director or studio: names
     * separated by commas, semicolons or bars): a name that uses a word of the hard list is left out, as a tag or a
     * category named with one is (1.2.3 refresh 2, N2-SAFE-01: such a name gets no creator channel either, and it is
     * no fact for visitors). The watch page (its details rows, their search links and the page's description), the SEO
     * templates' placeholders, the page's structured data, the home page's trending-actors rail and the search index
     * ask it; the admin keeps the stored field. Null when nothing may be shown.
     */
    public static function visibleNames(?string $names): ?string
    {
        $names = trim((string) $names);

        if ($names === '') {
            return null;
        }

        $kept = [];

        foreach (preg_split('/\s*[,;|]\s*/u', $names) ?: [] as $name) {
            $name = trim($name);

            if ($name !== '' && ! self::hides($name)) {
                $kept[] = $name;
            }
        }

        if ($kept === []) {
            return null;
        }

        $visible = implode(', ', array_values(array_unique($kept)));

        // (a phrase of the list written across the separators: nothing of the field is shown)
        return self::hides($visible) ? null : $visible;
    }

    /** Forget what this process remembered (tests). */
    public static function forget(): void
    {
        self::$hidden = [];
    }

    /**
     * The owner's words: the stored setting, one word or phrase per line (the default list while it was never saved;
     * an empty list once the owner saved it empty).
     *
     * @return list<string>
     */
    public static function siteWords(): array
    {
        try {
            $stored = Setting::getGroup(self::SETTING_GROUP);
        } catch (Throwable) {
            $stored = [];
        }

        return array_key_exists(self::SETTING, $stored) && $stored[self::SETTING] !== null
            ? self::parseWords((string) $stored[self::SETTING])
            : self::DEFAULT_WORDS;
    }

    /** The default list as the setting's text box shows it (config content_safety.default_words). */
    public static function defaultText(): string
    {
        return implode("\n", self::DEFAULT_WORDS);
    }

    /**
     * The words of a text box: one word or phrase per line (a comma or a semicolon also separates two), trimmed, each once;
     * too short words (see tooShort()) are left out, and at most MAX_WORDS of at most MAX_WORD_LENGTH characters are
     * used (the save refuses a list that breaks these rules, problems(), so a stored list never does).
     *
     * @return list<string>
     */
    public static function parseWords(?string $text): array
    {
        $words = [];

        foreach (self::lines($text) as $line) {
            $line = trim(mb_substr($line, 0, self::MAX_WORD_LENGTH));
            $normal = self::normalize($line);

            if (self::tooShort($normal) || isset($words[$normal])) {
                continue;
            }

            $words[$normal] = $line;

            if (count($words) >= self::MAX_WORDS) {
                break;
            }
        }

        return array_values($words);
    }

    /**
     * What is wrong with a text box of the owner's words, in the admin's language (the save refuses the list then): a
     * word too short, more than MAX_WORDS words, a word longer than MAX_WORD_LENGTH characters. Empty: the list is fine.
     *
     * @return list<string>
     */
    public static function problems(?string $text): array
    {
        $short = [];
        $long = [];
        $count = 0;
        $seen = [];

        foreach (self::lines($text) as $line) {
            $normal = self::normalize($line);

            if ($normal === '' || isset($seen[$normal])) {
                continue;
            }

            $seen[$normal] = true;
            $count++;

            if (mb_strlen($line) > self::MAX_WORD_LENGTH) {
                $long[] = mb_substr($line, 0, 20);
            } elseif (self::tooShort($normal)) {
                $short[] = $line;
            }
        }

        $problems = [];

        if ($short !== []) {
            $problems[] = __('These words are too short: :words. Use at least 3 letters (2 in Chinese, Japanese, Korean or Thai).', ['words' => implode(', ', array_slice($short, 0, 10))]);
        }

        if ($long !== []) {
            $problems[] = __('Use at most 100 characters for a word or phrase. Too long: the line that starts with ":start".', ['start' => $long[0]]);
        }

        if ($count > self::MAX_WORDS) {
            $problems[] = __('Use at most 500 words or phrases. This list has :count.', ['count' => number_format($count)]);
        }

        return $problems;
    }

    /**
     * A text as the lists are matched against: HTML entities decoded, compatibility forms and accents folded, lower
     * case, a clause's end (a comma, a semicolon, a colon, an exclamation or question mark, a full stop before a space)
     * a line break, every other run of characters that are not letters, marks, digits or line breaks one space.
     */
    public static function normalize(?string $text): string
    {
        $text = (string) $text;

        if ($text === '') {
            return '';
        }

        $text = mb_scrub($text, 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (class_exists(Normalizer::class)) {
            $compatible = Normalizer::normalize($text, Normalizer::FORM_KC);
            $text = is_string($compatible) ? $compatible : $text;
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
            $text = is_string($decomposed) ? (preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text) : $text;
        }

        // the dotless i of Turkish is the i of every other language once the case is folded (KIZ, kız)
        $text = str_replace('ı', 'i', mb_strtolower($text, 'UTF-8'));
        // the full stop of the abbreviation jr. (jr. high) ends no clause
        $text = preg_replace('/(?<![\p{L}\p{N}])jr\./u', 'jr ', $text) ?? $text;
        $text = preg_replace('/[,;:!?\x{060C}\x{061B}\x{061F}\x{3001}\x{3002}\x{FF61}\x{FF64}]+|\.(?=\s|$)/u', self::CLAUSE, $text) ?? $text;
        // (the vowel signs that take a place of their own, as in Hindi, are part of the word: \p{M} after the accents went)
        $text = preg_replace('/[^\p{L}\p{M}\p{N}\n'.self::CLAUSE.']+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ ?([\n'.self::CLAUSE.']) ?/u', '$1', $text) ?? $text;

        return trim($text, ' '.self::CLAUSE);
    }

    /** @return list<string> the lines of a text box (a comma or a semicolon also separates two), trimmed, not empty */
    private static function lines(?string $text): array
    {
        $lines = [];

        foreach (preg_split('/[\r\n,;\x{060C}\x{3001}\x{FF0C}\x{FF1B}]+/u', (string) $text) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Whether a normalised word of the owner's is too short to be one (see MIN_LENGTH, MIN_LENGTH_DENSE). */
    private static function tooShort(string $normal): bool
    {
        $letters = mb_strlen(str_replace([' ', "\n", self::CLAUSE], '', $normal));
        $dense = preg_match('/['.self::DENSE.'\p{Hangul}]/u', $normal) === 1;

        return $letters < ($dense ? self::MIN_LENGTH_DENSE : self::MIN_LENGTH);
    }

    /** @param iterable<mixed> $fields */
    private static function text(iterable $fields): string
    {
        $parts = [];

        foreach ($fields as $field) {
            if (is_array($field)) {
                foreach ($field as $value) {
                    if (is_scalar($value) && (string) $value !== '') {
                        $parts[] = str_replace(["\r", "\n"], ' ', (string) $value);
                    }
                }

                continue;
            }

            if (is_scalar($field) && (string) $field !== '') {
                $parts[] = str_replace(["\r", "\n"], ' ', (string) $field);
            }
        }

        return $parts === [] ? '' : self::normalize(implode("\n", $parts));
    }

    private static function hardPattern(): string
    {
        return self::$patterns['hard'] ??= (string) self::compileHard();
    }

    /** The hard list as one pattern: its words, the school levels with their persons, the other languages, the ages. */
    private static function compileHard(): ?string
    {
        $words = self::HARD;
        $starts = [];
        $anywhere = [];

        foreach (self::HARD_OTHER_LANGUAGES as $lists) {
            array_push($words, ...($lists['words'] ?? []));
            array_push($starts, ...($lists['starts'] ?? []));
            array_push($anywhere, ...($lists['anywhere'] ?? []));
        }

        $persons = implode('|', array_map(fn (string $person): string => preg_quote(self::normalize($person), '/'), self::HARD_SCHOOL_PERSONS));
        $levels = [];

        foreach (self::HARD_SCHOOL_LEVELS as $level) {
            $tokens = self::tokens($level);
            $levels[self::phrase($tokens)] = mb_substr($tokens[0], 0, 1);
        }

        // pattern => the letters it may start with (each group of the pattern is only tried where one of them is)
        $extraWords = [];

        foreach ($levels as $level => $first) {
            $extraWords[$level.'[ ]?(?:'.$persons.')(?:e?s)?'] = $first;
        }

        foreach (self::HARD_AGE as $i => $age) {
            $extraWords[$age] = self::HARD_AGE_FIRSTS[$i];
        }

        $counters = implode('|', array_map(fn (string $counter): string => preg_quote(self::normalize($counter), '/'), self::HARD_AGE_COUNTERS));
        $extraAnywhere = [
            '(?<!\p{N})(?:1[0-7]|[1-9])[ ]?(?:'.$counters.')' => '1-9',
            // loli in katakana with any other word after it (ロリ巨乳, ロリ可愛い), never inside a katakana word (カロリー, calorie)
            '(?<![\x{30A1}-\x{30FA}])ロリ(?![\x{30A1}-\x{30FA}\x{30FC}])' => 'ロ',
        ];

        return self::compile($words, 2, $starts, $anywhere, $extraWords, $extraAnywhere, $levels);
    }

    /**
     * One pattern for a list of words: whole words (the words of a phrase together or apart, a final s / es), word starts
     * and texts matched anywhere. A word of the owner's list goes where its script says (Chinese, Japanese, Thai and
     * Arabic anywhere, Korean as a word start, every other script as a whole word).
     *
     * @param  array<int, mixed>  $words  whole words (or, for the owner's list, any word)
     * @param  list<string>  $starts  word starts
     * @param  list<string>  $anywhere  texts matched anywhere
     * @param  array<string, string>  $extraWords  patterns matched as whole words => the letters they may start with
     * @param  array<string, string>  $extraAnywhere  patterns matched anywhere => the letters they may start with
     * @param  array<string, string>  $fields  patterns matched as a whole field (a tag, a category, a title that is
     *                                         nothing else) => the letters they may start with
     */
    private static function compile(array $words, int $minLength = self::MIN_LENGTH, array $starts = [], array $anywhere = [], array $extraWords = [], array $extraAnywhere = [], array $fields = []): ?string
    {
        $key = hash('sha256', json_encode([$words, $minLength, $starts, $anywhere, $extraWords, $extraAnywhere, $fields]) ?: '');

        if (isset(self::$patterns[$key])) {
            return self::$patterns[$key] === '' ? null : self::$patterns[$key];
        }

        // group => [alternative => [its length (the longest is tried first), the letters it may start with]]
        $groups = ['words' => [], 'abbreviations' => [], 'starts' => [], 'anywhere' => [], 'fields' => []];
        $add = static function (string $group, string $alternative, array $tokens) use (&$groups): void {
            $groups[$group][$alternative] = [mb_strlen(implode('', $tokens)), preg_quote(mb_substr($tokens[0], 0, 1), '/')];
        };

        foreach ($words as $word) {
            if (! is_scalar($word) || ($tokens = self::tokens((string) $word)) === []) {
                continue;
            }

            $script = self::script(implode('', $tokens));

            // two signs are a word in a script without spaces or in Korean (see tooShort())
            if (mb_strlen(implode('', $tokens)) < ($script === 'dense' || $script === 'hangul' ? min($minLength, self::MIN_LENGTH_DENSE) : $minLength)) {
                continue;
            }
            [$before, $after] = self::EXCEPT[(string) $word] ?? ['', ''];

            if ($script === 'dense' || $script === 'arabic') {
                $add('anywhere', $before.self::literal($tokens).$after, $tokens);
            } elseif ($script === 'hangul') {
                $add('starts', $before.self::literal($tokens).$after, $tokens);
            } elseif (count($tokens) === 1 && mb_strlen($tokens[0]) === 2 && preg_match('/^\p{L}{2}$/u', $tokens[0]) === 1) {
                // A two-letter abbreviation (JK): also with a dot or a space between its letters (J.K.), never before a
                // product code of three or more digits (JK-123); a short number is a part of a series (JK 2).
                [$first, $second] = mb_str_split($tokens[0]);
                $add('abbreviations', preg_quote($first, '/').'[ ]?'.preg_quote($second, '/').'(?:s)?', $tokens);
            } else {
                $add('words', $before.self::phrase($tokens).$after, $tokens);
            }
        }

        foreach ($starts as $start) {
            if (($tokens = self::tokens((string) $start)) !== []) {
                [$before, $after] = self::EXCEPT[(string) $start] ?? ['', ''];
                $add('starts', $before.self::literal($tokens).$after, $tokens);
            }
        }

        foreach ($anywhere as $text) {
            if (($tokens = self::tokens((string) $text)) !== []) {
                [$before, $after] = self::EXCEPT[(string) $text] ?? ['', ''];
                $add('anywhere', $before.self::literal($tokens).$after, $tokens);
            }
        }

        foreach (['words' => $extraWords, 'anywhere' => $extraAnywhere, 'fields' => $fields] as $group => $extra) {
            foreach ($extra as $pattern => $first) {
                $groups[$group][$pattern] = [0, $first];
            }
        }

        $parts = [];
        // A letter of a script written with spaces, or a digit: what a whole word may not touch (a Latin word next to
        // Chinese or Japanese letters is a word of its own).
        $wordSign = '[^\P{L}'.self::DENSE.']|\p{M}|\p{N}';
        $wordLetter = '[^\P{L}'.self::DENSE.']|\p{M}';

        foreach ($groups as $group => $alternatives) {
            if ($alternatives === []) {
                continue;
            }

            // The alternatives by the letters they start with, so a place of the text is only tried against those that
            // can start there (a pattern of a hundred words otherwise tries each of them at every word); in each, the
            // longest first: a phrase is found before a word it starts with.
            $buckets = [];

            foreach ($alternatives as $alternative => [$length, $letters]) {
                $buckets[$letters][$alternative] = $length;
            }

            $branches = [];

            foreach ($buckets as $letters => $members) {
                arsort($members);
                $branches[] = '(?=['.$letters.'])(?:'.implode('|', array_keys($members)).')';
            }

            // Only where a letter that one of them starts with is: that cheap test first, at every place of the text.
            $first = '(?=['.implode('', array_keys($buckets)).'])';
            $list = '(?:'.implode('|', $branches).')';
            $parts[] = match ($group) {
                'words' => $first.'(?<!'.$wordSign.')'.$list.'(?!'.$wordSign.')',
                'abbreviations' => $first.'(?<!'.$wordSign.')'.$list.'(?![ ]?\p{N}{3})(?!'.$wordLetter.')',
                'starts' => $first.'(?<!'.$wordSign.')'.$list,
                'anywhere' => $first.$list,
                // nothing else in the field: the start of the text or a line break before, the end or a line break after
                'fields' => $first.'(?<![^\n])'.$list.'(?![^\n])',
            };
        }

        if (count(self::$patterns) > 32) {
            self::$patterns = array_intersect_key(self::$patterns, ['hard' => true]);
        }

        $pattern = $parts === [] ? '' : '/'.implode('|', $parts).'/u';
        self::$patterns[$key] = $pattern;

        return $pattern === '' ? null : $pattern;
    }

    /** @return list<string> the normalised words of a word or phrase */
    private static function tokens(string $word): array
    {
        return array_values(array_filter(explode(' ', str_replace(["\n", self::CLAUSE], ' ', self::normalize($word))), fn (string $token): bool => $token !== ''));
    }

    /** 'dense' (a script written without spaces), 'arabic', 'hangul' or 'spaced' (every other script). */
    private static function script(string $text): string
    {
        return match (true) {
            preg_match('/['.self::DENSE.']/u', $text) === 1 => 'dense',
            preg_match('/\p{Arabic}/u', $text) === 1 => 'arabic',
            preg_match('/\p{Hangul}/u', $text) === 1 => 'hangul',
            default => 'spaced',
        };
    }

    /** The words of a phrase together or apart ("school girl", "schoolgirl", "school-girl"), a final s / es. */
    private static function phrase(array $tokens): string
    {
        $last = count($tokens) - 1;
        $parts = [];

        foreach ($tokens as $i => $token) {
            $parts[] = preg_quote($token, '/').($i === $last ? '(?:e?s)?' : '');
        }

        return implode('[ ]?', $parts);
    }

    /** A text as it is written (its words apart or together), no plural. */
    private static function literal(array $tokens): string
    {
        return implode('[ ]?', array_map(fn (string $token): string => preg_quote($token, '/'), $tokens));
    }

    private static function firstMatch(string $pattern, ?string $text): ?string
    {
        $normal = self::normalize($text);

        return $normal !== '' && preg_match($pattern, $normal, $match) === 1 ? $match[0] : null;
    }
}
