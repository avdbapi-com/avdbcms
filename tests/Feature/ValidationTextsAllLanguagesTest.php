<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Judge R2A-049: R1-121 rewrote the validation sentences of English, Russian and Arabic in plain words; French kept
 * Laravel's generic ones ("Le texte nom d'utilisateur doit contenir au moins 3 caractères.", "adresse courriel" where
 * the whole site says "e-mail") and 16 of the 17 other languages still had 98 of their lines in English ("The avatar
 * failed to upload."). Every language now has every line in its own plain words, with the field names of the forms.
 *
 * Review of the fix: CA-RV-01, capitalised names inside a sentence ("The Profile picture failed to upload.", the wording
 * R1-121 removed): where a language writes the name without quotes and has capital letters, names are in lower case and
 * :Attribute capitalises the name where it starts the sentence. CA-RV-02: French had lost 22 names int-C2 had, and the
 * fields the admin's forms, the comments and the History API validate (content, year, country, date ...) printed their
 * key ("Le format du champ « year » ..."): every language names the same fields, French's former ones among them.
 */
class ValidationTextsAllLanguagesTest extends TestCase
{
    use RefreshDatabase;

    private const PLACEHOLDER = '/:(attribute|other|values|value|date|min|max|size|digits|format)(?![A-Za-z])/i';

    /** The names int-C2's French file had (Laravel's French set): none may be lost (CA-RV-02). */
    private const INT_C2_FRENCH_NAMES = [
        'name', 'username', 'email', 'cgu', 'first_name', 'last_name', 'password', 'password_confirmation', 'city',
        'country', 'address', 'phone', 'mobile', 'age', 'sex', 'gender', 'day', 'month', 'year', 'hour', 'minute',
        'second', 'title', 'content', 'description', 'excerpt', 'date', 'time', 'available', 'size',
    ];

    /** Fields people type on the member pages and in the admin's forms that no form names (CA-RV-02). */
    private const TYPED_FIELDS = [
        'content', 'year', 'country', 'date', 'comment', 'reason', 'subject', 'body', 'to', 'slug', 'meta_title',
        'meta_description', 'sort_order', 'price', 'duration', 'days', 'ip_address', 'image_url', 'link_url',
        'starts_at', 'expires_at', 'source', 'destination', 'icon', 'file', 'label', 'code', 'user_id', 'video_id',
        'thumbnail_file', 'pages', 'start_page', 'pagesize', 'delay', 'updated_hours',
    ];

    /** Languages with capital letters that write the name without quotes: names in lower case (CA-RV-01). */
    private const LOWER_CASE_NAMES = ['en', 'id', 'ms', 'vi', 'fil', 'tl'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'site.captcha_provider' => 'none', 'site.max_register_attempts' => 1000, 'cache.limiter' => 'array']);
        Cache::flush();
        RateLimiter::clear('register|127.0.0.1');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        app()->setLocale('en');

        parent::tearDown();
    }

    /** @return array<string, string> "key.sub" => line */
    private static function lines(string $locale): array
    {
        $flat = [];
        $walk = function (array $lines, string $prefix) use (&$walk, &$flat): void {
            foreach ($lines as $key => $line) {
                if (is_array($line)) {
                    $walk($line, $prefix.$key.'.');
                } else {
                    $flat[$prefix.$key] = (string) $line;
                }
            }
        };
        $walk(require lang_path($locale.'/validation.php'), '');

        return array_filter($flat, static fn (string $key): bool => ! str_starts_with($key, 'custom.') && ! str_starts_with($key, 'attributes.'), ARRAY_FILTER_USE_KEY);
    }

    /** @return list<string> the placeholders of a line, :attribute in any case counted once */
    private static function placeholders(string $line): array
    {
        preg_match_all(self::PLACEHOLDER, $line, $match);
        $names = array_values(array_unique(array_map('strtolower', $match[1])));
        sort($names);

        return $names;
    }

    public function test_every_language_has_every_line_in_its_own_words_with_the_english_placeholders(): void
    {
        $english = self::lines('en');
        $locales = array_values(array_unique(array_merge(array_keys((array) config('languages')), ['fil'])));

        foreach ($locales as $locale) {
            if ($locale === 'en') {
                continue;
            }
            $lines = self::lines($locale);

            $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($lines))), $locale.': missing lines');
            foreach ($english as $key => $line) {
                $this->assertNotSame($line, $lines[$key], $locale.' '.$key.' is still the English line');
                $want = array_values(array_diff(self::placeholders($line), $key === 'email' ? ['attribute'] : []));
                $have = array_values(array_diff(self::placeholders($lines[$key]), $key === 'email' ? ['attribute'] : []));
                $this->assertSame($want, $have, $locale.' '.$key.': '.$lines[$key]);
                // only :attribute is written in three cases by Laravel; ":Other" would be printed as it is
                $this->assertDoesNotMatchRegularExpression('/:(Other|Values?|Date|Min|Max|Size|Digits|Format)(?![A-Za-z])/', $lines[$key], $locale.' '.$key);
            }

            $names = (require lang_path($locale.'/validation.php'))['attributes'];
            foreach (['username', 'email', 'password', 'password_confirmation', 'cgu', 'title', 'description', 'category_id', 'language', 'name', 'message', 'avatar', 'banner'] as $field) {
                $this->assertNotEmpty($names[$field] ?? null, $locale.': the name of '.$field);
                // a name may be the same word as its key where the language uses that word (Vietnamese and Filipino
                // "email", Filipino "username", "password", "banner"), never a key a person does not read
                if ($names[$field] === $field) {
                    $this->assertContains($field, ['username', 'email', 'password', 'banner'], $locale.': '.$field.' is named, not its key');
                }
            }
        }
    }

    public function test_every_language_names_the_same_fields_and_french_keeps_its_former_names(): void
    {
        $english = (require lang_path('en/validation.php'))['attributes'];
        $locales = array_values(array_unique(array_merge(array_keys((array) config('languages')), ['fil'])));

        foreach (array_merge(self::INT_C2_FRENCH_NAMES, self::TYPED_FIELDS) as $field) {
            $this->assertArrayHasKey($field, $english, 'en: '.$field);
        }

        foreach ($locales as $locale) {
            $names = (require lang_path($locale.'/validation.php'))['attributes'];

            $this->assertSame(array_keys($english), array_keys($names), $locale.': the same fields as English, in the same order');
            foreach ($names as $field => $name) {
                $this->assertIsString($name);
                $this->assertSame(trim($name), $name, $locale.' '.$field);
                $this->assertNotSame('', $name, $locale.' '.$field);
            }
        }

        // nothing int-C2's French file named is lost (CA-RV-02: it had 30 names, the first pass kept 13)
        $french = (require lang_path('fr/validation.php'))['attributes'];
        $this->assertSame([], array_values(array_diff(self::INT_C2_FRENCH_NAMES, array_keys($french))));
    }

    public function test_a_field_no_form_names_is_named_in_every_language_not_by_its_key(): void
    {
        $locales = array_values(array_unique(array_merge(array_keys((array) config('languages')), ['fil'])));

        foreach ($locales as $locale) {
            app()->setLocale($locale);
            $names = (require lang_path($locale.'/validation.php'))['attributes'];

            foreach (self::TYPED_FIELDS as $field) {
                $sentence = validator([$field => ''], [$field => 'required'])->errors()->first($field);
                $name = $names[$field];

                $this->assertTrue(
                    str_contains($sentence, $name) || str_contains($sentence, Str::ucfirst($name)),
                    $locale.' '.$field.': "'.$sentence.'" names it "'.$name.'"'
                );
                // the key itself is not printed (unless the language's name is that word: "code", "file", "Slug")
                if (! str_contains(mb_strtolower($name), $field)) {
                    $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}\p{N}_])'.preg_quote($field, '/').'(?![\p{L}\p{N}_])/u', $sentence, $locale.' '.$field.': the key is not printed');
                }
            }
        }

        // the reviewer's cases (the admin's import forms, the comments, the History API, Members > edit)
        $nbsp = "\u{00A0}";
        $cases = [
            'fr' => [
                ['year', ['nullable', 'string', 'regex:/^\d{4}$/'], '20x4', "Le format du champ «{$nbsp}Année{$nbsp}» n'est pas valide."],
                ['content', 'required', '', "Le champ «{$nbsp}Contenu{$nbsp}» est obligatoire."],
                ['date', 'required|date_format:Y-m-d', '2026/10/28', "Le champ «{$nbsp}Date{$nbsp}» ne suit pas le format Y-m-d."],
                ['country', 'string|max:2', 'FRA', "Le champ «{$nbsp}Pays{$nbsp}» ne doit pas dépasser 2 caractères."],
            ],
            'de' => [['year', ['nullable', 'string', 'regex:/^\d{4}$/'], '20x4', 'Das Format von „Jahr“ ist ungültig.']],
            'ja' => [['year', ['nullable', 'string', 'regex:/^\d{4}$/'], '20x4', '年の形式が正しくありません。']],
            'zh' => [['content', 'string|max:10', str_repeat('x', 12), '内容不能超过 10 个字符。']],
            'en' => [['ip_address', 'ip', 'nope', 'The IP address must be a valid IP address.']],
        ];
        foreach ($cases as $locale => $rows) {
            app()->setLocale($locale);
            foreach ($rows as [$field, $rule, $value, $sentence]) {
                $this->assertSame($sentence, validator([$field => $value], [$field => $rule])->errors()->first($field), $locale.' '.$field);
            }
        }
    }

    public function test_names_inside_a_sentence_are_not_capitalised_where_the_language_writes_them_without_quotes(): void
    {
        foreach (self::LOWER_CASE_NAMES as $locale) {
            $file = require lang_path($locale.'/validation.php');

            // the names are in lower case (an acronym such as "IP" may lead)
            foreach ($file['attributes'] as $field => $name) {
                $first = explode(' ', $name)[0];
                $this->assertTrue(mb_strtolower(mb_substr($name, 0, 1)) === mb_substr($name, 0, 1) || mb_strtoupper($first) === $first, $locale.' '.$field.': '.$name);
            }

            // a sentence that starts with the name capitalises it (:Attribute), any other place keeps it as it is
            foreach (self::lines($locale) as $key => $line) {
                preg_match_all('/:(attribute)(?![A-Za-z])/i', $line, $found, PREG_OFFSET_CAPTURE);
                foreach ($found[1] as [$spelt, $offset]) {
                    $before = rtrim(substr($line, 0, $offset - 1));
                    $starts = $before === '' || preg_match('/[.!?]$/', $before) === 1;
                    $this->assertSame($starts ? 'Attribute' : 'attribute', $spelt, $locale.' '.$key.': '.$line);
                }
            }
        }

        app()->setLocale('en');
        $this->assertSame('The selected category was not found.', validator(['category_id' => 999], ['category_id' => 'exists:categories,id'])->errors()->first('category_id'));
        $this->assertSame('The profile picture must be an image.', validator(['avatar' => 'x'], ['avatar' => 'image'])->errors()->first('avatar'));
        $this->assertSame('The selected language is invalid.', validator(['language' => 'zz'], ['language' => 'in:en,fr'])->errors()->first('language'));
        $this->assertSame('Title is required.', validator(['title' => ''], ['title' => 'required'])->errors()->first('title'));
        $this->assertSame('The end date must be a date after start date.', validator(['starts_at' => '2026-10-28', 'expires_at' => '2026-10-27'], ['expires_at' => 'date|after:starts_at'])->errors()->first('expires_at'));

        app()->setLocale('id');
        $this->assertSame('Nilai yang dipilih untuk kategori tidak valid.', validator(['category_id' => 9], ['category_id' => 'in:1,2'])->errors()->first('category_id'));
        $this->assertSame('Judul wajib diisi.', validator(['title' => ''], ['title' => 'required'])->errors()->first('title'));
        app()->setLocale('vi');
        $this->assertSame('Bạn cần chấp nhận điều khoản dịch vụ.', validator(['cgu' => '0'], ['cgu' => 'accepted'])->errors()->first('cgu'));
        app()->setLocale('tl');
        $this->assertSame('Kailangan ang pamagat.', validator(['title' => ''], ['title' => 'required'])->errors()->first('title'));

        // Turkish keeps capitals (ucfirst() cannot write the dotted İ): the name starts the sentence
        app()->setLocale('tr');
        $lines = self::lines('tr');
        $this->assertStringStartsWith(':Attribute', $lines['unique']);
        $this->assertStringStartsWith(':Attribute', $lines['password.uncompromised']);
        User::factory()->create(['username' => 'taken_tr']);
        $this->assertSame('Kullanıcı adı zaten kullanılıyor.', validator(['username' => 'taken_tr'], ['username' => 'unique:users,username'])->errors()->first('username'));
        $this->assertSame('İsim alanı zorunludur.', validator(['name' => ''], ['name' => 'required'])->errors()->first('name'));
    }

    public function test_a_picture_the_server_could_not_receive_is_named_in_the_members_language(): void
    {
        // Account settings names most of its fields itself, not its two pictures: a picture over the host's upload
        // limit was "The avatar failed to upload." in every language ("تعذر رفع ملف حقل avatar.", "avatarを...").
        $member = User::factory()->create();
        $expected = [
            'en' => ['avatar' => 'The profile picture failed to upload.', 'banner' => 'The banner failed to upload.'],
            'fr' => ['avatar' => "Le fichier «\u{00A0}Photo de profil\u{00A0}» n'a pas pu être envoyé.", 'banner' => "Le fichier «\u{00A0}Bannière\u{00A0}» n'a pas pu être envoyé."],
            'ar' => ['avatar' => 'تعذر رفع ملف حقل صورة الملف الشخصي.', 'banner' => 'تعذر رفع ملف حقل البانر.'],
            'ja' => ['avatar' => 'プロフィール写真をアップロードできませんでした。', 'banner' => 'バナーをアップロードできませんでした。'],
        ];

        foreach ($expected as $locale => $errors) {
            foreach ($errors as $field => $sentence) {
                // a file PHP refused before the application saw it (upload_max_filesize)
                $refused = new UploadedFile(UploadedFile::fake()->image('me.png', 200, 200)->getPathname(), 'me.png', 'image/png', UPLOAD_ERR_INI_SIZE, true);

                app()->setLocale('en'); // a new request starts in the site's language
                $this->actingAs($member)->withSession(['app_locale' => $locale])->from(route('user.edit'))
                    ->put(route('user.update'), ['section' => $field === 'avatar' ? 'profile' : 'channel', $field => $refused])
                    ->assertSessionHasErrors([$field => $sentence]);
            }
        }
    }

    public function test_the_judges_sign_up_case_reads_in_plain_words(): void
    {
        $expected = [
            'fr' => ['username' => "Le champ «\u{00A0}Nom d'utilisateur\u{00A0}» doit contenir au moins 3 caractères.", 'email' => 'Saisissez une adresse e-mail valide, comme name@example.com.'],
            'de' => ['username' => 'Das Feld „Benutzername“ muss mindestens 3 Zeichen lang sein.', 'email' => 'Geben Sie eine gültige E-Mail-Adresse ein, z. B. name@example.com.'],
            'es' => ['username' => 'El campo «Nombre de usuario» debe tener al menos 3 caracteres.', 'email' => 'Escribe una dirección de correo válida, como name@example.com.'],
        ];

        foreach ($expected as $locale => $errors) {
            $this->flushSession();
            $_SERVER['DETECTED_LOCALE'] = $locale;
            app()->setLocale($locale); // public/index.php, for /<locale>/register

            $this->from('/register')->post('/register', ['username' => 'x', 'email' => 'not-an-email', 'password' => 'short1', 'password_confirmation' => 'other1', 'cgu' => '1'])
                ->assertSessionHasErrors(['username', 'email']);

            $this->assertSame($errors['username'], (string) session('errors')->first('username'), $locale);
            $this->assertSame($errors['email'], (string) session('errors')->first('email'), $locale);
            $this->assertStringNotContainsString('courriel', (string) session('errors')->first('email'));
        }
    }

    public function test_a_field_without_a_name_on_its_form_uses_the_name_of_the_language(): void
    {
        foreach (['de' => 'Das Feld „Titel“ ist erforderlich.', 'en' => 'Title is required.', 'ja' => 'タイトルを入力してください。'] as $locale => $sentence) {
            app()->setLocale($locale);
            $validator = validator(['title' => ''], ['title' => 'required']);

            $this->assertSame($sentence, $validator->errors()->first('title'), $locale);
        }

        // the English line that read "This category id is not found in ours records"
        app()->setLocale('en');
        $this->assertSame('The selected category was not found.', validator(['category_id' => 999], ['category_id' => 'exists:categories,id'])->errors()->first('category_id'));
    }
}
