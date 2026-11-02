<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 1.2.3 round 3 pre-fix pass, R2R-04: Spanish mixed the two forms of "you". Pronouns were "tú" in 355 texts against
 * "usted" in 14, but formal commands ("Inténtelo", "Elija", "Introduzca") were in 229 texts and "su sitio" / "sus
 * videos" told the reader "your" formally in hundreds more. Decided: "tú" everywhere (member pages, studio, account,
 * admin, mails, the messages of the apps and of the site), with the exception German has: the upload texts the owner
 * keeps as they are (AccountPremiumRoundTwoTest::test_german_says_sie_on_every_page_but_the_upload_texts_the_owner_keeps:
 * the same keys stay as Spanish has them).
 *
 * The other languages with a formal and an informal "you" were aligned to the form most of their texts use: French
 * "vous", Russian "вы", Turkish "siz", Indonesian "Anda", Malay "anda", Hindi "आप", Chinese "您" (483 texts against 288
 * with "你"), Filipino / Tagalog without "po"; German says "Sie" (its PHP language files said "du" in 9 lines).
 * A language's form of "you" is in its commands too, so the minority patterns hold them as well (second pass, after
 * the review found what the first patterns could not see): the Hindi "तुम" commands ("पर छोड़ दो।" in six "Leave on."
 * texts, "अभी भागो", "फिर से दिखाओ", "पहले बचाओ", "वीडियो ढूंढ़ो": a danda "।" ends a word, so the letter class leaves
 * it out), the Turkish "sen" commands that end a sentence ("Devam et.", "Başka bir kelime dene.", "Bunları açık
 * bırak."; a one-line button label such as "Kaydet" is not a sentence and keeps its bare stem, as Turkish buttons do),
 * the Russian "ты" commands ("Беги сейчас", "Покажи", "Удиви меня", "Возвращайся"), French in any case ("Toi") with
 * "ta", "t'" and the "tu" command before a pronoun ("Montre-le"), the Korean "너" and banmal endings ("열어라",
 * "기다리지 않아"), the Indonesian "aku" next to "Anda", and the Portuguese "tu" forms.
 *
 * Every text a source uses is checked, as the translation tools collect them (scripts/i18n Requirements: PHP, Blade, the
 * settings catalogue, the Preact texts), and every line of the PHP language files.
 */
class FormOfAddressAllLanguagesTest extends TestCase
{
    /** The upload window's texts and those of a video Upload18 is still processing: the owner keeps them (25 Oct). */
    private const OWNER_KEEPS = [
        'Your upload limits',
        'By submitting your videos to :app_name, you acknowledge that you agree to our <a href=":terms_url" class="text-decoration-none">:terms_text</a>',
        'The processing of your video failed.',
        'Try to re-upload your file, if the problem persist please contact your support..',
        'Your video is processing',
        'Your video is processing ...',
        'Your video is almost ready ...',
        'You will receive a notification once the processing is complete',
        'You will receive a notification once the processing is complete.',
        'Upload18 returned an older video instead of your file, so it was not added. Please upload it again.',
        'The video Upload18 returned does not match your file, so it was not added. Please upload it again.',
        'js: Your video is safe.',
        'js: Upload18 is still processing your video.',
        'js: Upload18 is still processing it. It will appear in your studio when it is ready, so you can close this page.',
        'js: Your video is uploading ...',
    ];

    /** Formal commands as Spanish UI texts write them (the informal form of each one is what the site says). */
    private const SPANISH_FORMAL_COMMANDS = [
        'Elija', 'Introduzca', 'Escriba', 'Pruebe', 'Compruebe', 'Utilice', 'Use', 'Haga', 'Pulse', 'Seleccione', 'Espere',
        'Ingrese', 'Revise', 'Contacte', 'Consulte', 'Cambie', 'Añada', 'Agregue', 'Suba', 'Descargue', 'Inicie', 'Confirme',
        'Verifique', 'Mantenga', 'Elimine', 'Guarde', 'Abra', 'Copie', 'Comparta', 'Lea', 'Envíe', 'Pida', 'Solicite',
        'Actualice', 'Disfrute', 'Ahorre', 'Cancele', 'Vuelva', 'Pegue', 'Active', 'Desactive', 'Habilite', 'Deshabilite',
        'Establezca', 'Ejecute', 'Instale', 'Cree', 'Busque', 'Explore', 'Genere', 'Realice', 'Encuentre', 'Corrija',
        'Purgue', 'Revierta', 'Notifique', 'Vea', 'Mire', 'Obtenga', 'Defina', 'Asigne', 'Marque', 'Deje', 'Quite', 'Arrastre',
        'Libere', 'Transforme', 'Muestre', 'Exporte', 'Bloquee', 'Permita', 'Evite', 'Recuerde', 'Limpie', 'Indique',
        'Reproduzca', 'Coloque', 'Tenga', 'Ponga', 'Diga', 'Vaya', 'Venga', 'Siga', 'Lleve', 'Tome', 'Responda', 'Reinicie',
        'Restablezca', 'Restaure', 'Recupere', 'Reanude', 'Detenga', 'Termine', 'Finalice', 'Continúe', 'Prepare', 'Cuente',
        'Rellene', 'Programe', 'Publique', 'Visite', 'Ajústelo', 'Inténtelo', 'Inténtela', 'Hágalo', 'Déjelo', 'Déjela',
        'Péguelo', 'Péguela', 'Cópielo', 'Guárdelo', 'Actívelo', 'Desactívelo', 'Elimínelo', 'Cámbielo', 'Bájelo', 'Súbalo',
        'Ábralo', 'Compruébelo', 'Pruébelo', 'Revíselo', 'Ejecútelo', 'Publíquelo', 'Quítelo', 'Cancélelo', 'Termínelo',
        'Escríbanos', 'Contáctenos', 'Manténgala', 'Manténgalo', 'Tradúzcalo', 'Continúela', 'Cancélela', 'Reviértala',
        'Controle', 'Agrupe', 'Páuselo', 'Pauselo', 'Úselo', 'Dele', 'Presione', 'Sea breve',
    ];

    /** Spanish texts that are names, where a word looks like a formal command ("Pulse Sport" is a pair of fonts). */
    private const SPANISH_NAMES = ['Pulse Sport: Saira Condensed + Saira'];

    /**
     * The minority form of each language whose texts were aligned (the majority form is what the site says). Each
     * pattern is matched in a text whose placeholders and HTML are taken out.
     */
    private const MINORITY = [
        // in any case ("Toi"); "ta", "t'"; a "tu" command before its pronoun ("Montre-le"; not the "vous" commands in -ez,
        // "Faites-le", "Dites-le")
        'fr' => '/(?<![\p{L}\'’-])(tu|toi|ton|ta|tes|te|tien|tienne|tiens|tiennes)(?![\p{L}\'’])|(?<![\p{L}\'’-])t[\'’](?=\p{L})|-(tu|toi|te)(?!\p{L})|(?<!\p{L})(?!(?:faites|dites)-)\p{L}+(?<!ez)-(le|la|les|moi)(?!\p{L})/iu',
        'ru' => '/(?<!\p{L})(ты|тебя|тебе|тобой|твой|твоя|твоё|твое|твои|твоего|твоей|твоих|твоим|твою|оставь|попробуй|выбери|нажми|введи|начни|проверь|удиви|покажи|беги|возвращайся|вернись|сохрани|открой|закрой|добавь|удали|скачай|загрузи|включи|выключи|подожди|обнови|посмотри|перейди|напиши|отправь|используй|сделай)(?!\p{L})/iu',
        // the pronouns and -sin endings, and a "sen" command that ends a sentence: before a full stop that is not an
        // ellipsis ("Devam et."), or after an earlier sentence ("... mi? Tekrar içeri gir")
        'tr' => '/(?<!\p{L})(sen|senin|seni|sana|sende|senden|Sen|Senin|Seni|Sana|misin|mısın|musun|müsün|\p{L}+(?:abilirsin|ebilirsin))(?!\p{L})|(?<!\p{L})(?i:bırak|devam et|dene|gir|kaydet|seç|kullan|tıkla|bekle|kontrol et)[.!](?!\.)|[.?!]\s[^.?!]*(?<!\p{L})(?i:bırak|devam et|dene|gir|kaydet|seç|kullan|tıkla|bekle|kontrol et)$/u',
        'id' => '/(?<!\p{L})(kamu|kau|engkau|aku|Kamu|Kau|Aku|\p{L}+(?:kanmu|nyamu|namu|lihanmu|tukmu|warnamu))(?!\p{L})/u',
        'ms' => '/(?<!\p{L})(kamu|awak|engkau|Kamu|Awak)(?!\p{L})/u',
        // Devanagari letters and signs without the dandas (U+0964 "।", U+0965), which end a word like a full stop
        'hi' => '/(?<![\x{0900}-\x{0963}\x{0966}-\x{097F}])(तुम|तुम्हारा|तुम्हारी|तुम्हारे|तुम्हें|तू|तेरा|तेरी|तेरे|करो|बनाओ|हटाओ|देखो|जाओ|दिखाओ|बचाओ|ढूंढ़ो|ढूंढो|ढूँढो|भागो|दौड़ो|चलाओ|चुनो|खोलो|लगाओ|रखो|लिखो|भेजो|बताओ|छोड़ो|जोड़ो|बदलो|दबाओ|रोको|सुनो|पढ़ो|आओ|सहेजो|आज़माओ|छोड़ दो|दे दो|कर दो)(?![\x{0900}-\x{0963}\x{0966}-\x{097F}])/u',
        'pt' => '/(?<!\p{L})(tu|teu|tua|teus|tuas|contigo|ti)(?!\p{L})/iu',
        'zh' => '/(?<!迷)你/u',
        'fil' => '/(?<!\p{L})po(?!\p{L})/u',
        'tl' => '/(?<!\p{L})po(?!\p{L})/u',
        // 너 and the banmal endings of a sentence ("주인을 열어라", "아무것도 기다리지 않아"): the site says 귀하 / 본인 and -요 / -니다
        'ko' => '/(?<![가-힣])(너|너의|너를|너에게|네가|니가|넌|널)(?![가-힣])|[가-힣]*(?:어라|아라|해라|않아|거야|했어|있어|없어)(?=\s*(?:[.!?]|$))/u',
    ];

    /** Texts that name a thing with a minority word that is not an address of the reader. */
    private const NOT_AN_ADDRESS = [
        'hi' => ['Merge two tags', 'Two letters, e.g. US or VN.'],
    ];

    /** @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, true>, 3: array<string, true>} */
    private function texts(string $locale): array
    {
        require_once base_path('scripts/i18n/I18n.php');
        [$json] = \Avd\I18n\LangFile::read(lang_path($locale.'.json'));
        $jsPath = \Avd\I18n\JsLangFile::path(base_path(), $locale);
        $js = is_file($jsPath) ? \Avd\I18n\JsLangFile::read($jsPath)[0] : [];

        return [$json, $js, \Avd\I18n\Requirements::collect(base_path()), \Avd\I18n\Requirements::collectJs(base_path())];
    }

    /**
     * The menus' titles (config/menu.php, translated when the menus are drawn): shown on every page, but the translation
     * tools do not collect them ("Your videos" said 你 in Chinese).
     *
     * @return list<string>
     */
    private function menuTitles(): array
    {
        $titles = [];
        $menus = (array) config('menu');
        array_walk_recursive($menus, function ($value, $key) use (&$titles): void {
            if ($key === 'title' && is_string($value)) {
                $titles[] = $value;
            }
        });

        return array_values(array_unique($titles));
    }

    /** @return array<string, string> "file.key.sub" => line of lang/<locale>/*.php */
    private function phpLines(string $locale): array
    {
        $flat = [];
        $walk = function (array $lines, string $prefix) use (&$walk, &$flat): void {
            foreach ($lines as $key => $line) {
                is_array($line) ? $walk($line, $prefix.$key.'.') : $flat[$prefix.$key] = (string) $line;
            }
        };
        foreach (glob(lang_path($locale.'/*.php')) ?: [] as $file) {
            $walk(require $file, basename($file, '.php').'.');
        }

        return $flat;
    }

    private function plain(string $text): string
    {
        return trim((string) preg_replace('/<[^>]+>|:[a-z_]+|\{[a-z_]+\}|\s+/u', ' ', $text));
    }

    /** The formal Spanish of a text, if any: "usted", a formal reflexive command, a formal command or question. */
    private function spanishFormal(string $value): ?string
    {
        $text = $this->plain($value);
        if (preg_match('/(?<!\p{L})(usted|ustedes|Ud\.|Uds\.)(?!\p{L})/iu', $text, $m)) {
            return $m[1];
        }
        // "Asegúrese", "Regístrese", "Suscríbase", "Desplácese" (informal: -te); not a gerund ("registrándose")
        if (preg_match('/(?<!\p{L})(\p{L}*[áéíóú]\p{L}*[ae]se)(?!\p{L})/u', $text, $m) && ! preg_match('/nd[oa]se$/u', $m[1])) {
            return $m[1];
        }
        if (preg_match('/¿\s*(Está seguro|Está segura|Desea|Quiere)(?!\p{L})/u', $text, $m)) {
            return $m[1];
        }
        $commands = implode('|', self::SPANISH_FORMAL_COMMANDS);
        // where a command stands: the start of the text, of a sentence, of a plural form or of a bracket, after
        // "Por favor", "y" / "o" / "luego" / "primero"; in either case ("y vuelva a intentarlo", "(presione L ...)").
        // Not "¡Vaya!" (an interjection: "Whoops!").
        $start = '(?:^|[.!?:;|¡¿"«(]\s*|\}\s*|\]\s*|Por favor,?\s+|(?<!\p{L})(?:y|o|luego|primero)\s+)';
        if (preg_match('/'.$start.'((?i:'.$commands.'))(?![\p{L}!])/u', $text, $m)) {
            return $m[1];
        }
        // a formal command right after a sentence start word ("Primero haga clic", "Luego borre")
        if (preg_match('/(?:^|[.!?:;]\s+)(?:Primero|Luego|Después|Ahora|También|Mejor)\s+((?i:'.$commands.'))(?!\p{L})/u', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    public function test_spanish_says_tu_on_every_page_but_the_upload_texts_the_owner_keeps(): void
    {
        [$json, $js, $required, $requiredJs] = $this->texts('es');
        $this->assertGreaterThan(8000, count($required));
        $this->assertGreaterThan(150, count($requiredJs));

        $formal = [];
        foreach (array_keys($required) as $key) {
            if (in_array($key, self::SPANISH_NAMES, true)) {
                continue;
            }
            if (is_string($json[$key] ?? null) && ! in_array($key, self::OWNER_KEEPS, true) && ($word = $this->spanishFormal($json[$key])) !== null) {
                $formal[] = $key.'  ['.$word.']';
            }
        }
        foreach (array_keys($requiredJs) as $key) {
            if (is_string($js[$key] ?? null) && ! in_array('js: '.$key, self::OWNER_KEEPS, true) && ($word = $this->spanishFormal($js[$key])) !== null) {
                $formal[] = 'js: '.$key.'  ['.$word.']';
            }
        }
        foreach ($this->menuTitles() as $title) {
            if (is_string($json[$title] ?? null) && ($word = $this->spanishFormal($json[$title])) !== null) {
                $formal[] = 'menu: '.$title.'  ['.$word.']';
            }
        }

        $this->assertSame([], $formal, 'a Spanish text says "usted" where the site says "tú"');
    }

    /**
     * "your" told formally ("su sitio", "sus videos") where every other text says "tu": a text whose English says
     * "your" and whose Spanish has "su"/"sus" but no "tu"/"tus" (its own thing: "su miniatura" of a video, is fine
     * where the English names that thing).
     */
    public function test_spanish_says_tu_sitio_not_su_sitio(): void
    {
        [$json, $js, $required, $requiredJs] = $this->texts('es');
        $mixed = [];
        $check = function (string $key, ?string $value, string $label) use (&$mixed): void {
            if (! is_string($value) || in_array($label, self::OWNER_KEEPS, true)) {
                return;
            }
            $english = $this->plain(str_starts_with($label, 'js: ') ? substr($label, 4) : $label);
            $spanish = $this->plain($value);
            if (preg_match('/\byour\b/i', $english) && preg_match('/(?<!\p{L})(su|sus)\s+(sitio|propio sitio|cuenta|canal|contraseña|correo|dirección|base de datos|panel|plan|planes|archivos|videos|vídeos|página|páginas|clave|claves|servidor|proveedor|diseño|tema|logotipo|marca|equipo|atención|información|datos|historial|actividad|biblioteca|perfil|suscripción|app|aplicación|navegador|dispositivo|nombre de usuario|imagen|foto|lista|listas|textos|cambios|ajustes|configuración)(?!\p{L})/iu', $spanish, $m)
                && ! preg_match('/(?<!\p{L})(tu|tus|tuyo|tuya|tuyos|tuyas)(?!\p{L})/iu', $spanish)) {
                $mixed[] = $label.'  ['.$m[0].']';
            }
        };
        foreach (array_keys($required) as $key) {
            $check($key, $json[$key] ?? null, $key);
        }
        foreach (array_keys($requiredJs) as $key) {
            $check($key, $js[$key] ?? null, 'js: '.$key);
        }

        $this->assertSame([], $mixed, 'a Spanish text says "su ..." for the reader\'s own thing');
    }

    public function test_the_upload_texts_the_owner_keeps_stay_as_spanish_has_them(): void
    {
        [$json, $js] = $this->texts('es');
        // as 1.2.3's candidates wrote them (int-C3): this pass changed none of them; the owner approved one word for a
        // video in 'Your video is uploading ...' too (OQ-10, 30 Oct 2026: "video", as the texts next to it say)
        $expected = [
            'Your upload limits' => 'Tus límites de subida',
            'The processing of your video failed.' => 'El procesamiento de tu video falló.',
            'Your video is processing' => 'Tu video se está procesando',
            'You will receive a notification once the processing is complete.' => 'Recibirás una notificación una vez que se complete el procesamiento.',
            'js: Your video is safe.' => 'Tu video está a salvo.',
            'js: Your video is uploading ...' => 'Tu video se está subiendo...',
        ];

        foreach ($expected as $key => $spanish) {
            $value = str_starts_with($key, 'js: ') ? ($js[substr($key, 4)] ?? null) : ($json[$key] ?? null);
            $this->assertSame($spanish, $value, $key);
        }
        foreach (self::OWNER_KEEPS as $key) {
            $value = str_starts_with($key, 'js: ') ? ($js[substr($key, 4)] ?? null) : ($json[$key] ?? null);
            $this->assertIsString($value, $key.' keeps its Spanish');
            $this->assertNull($this->spanishFormal($value), $key.' already says "tú": nothing to keep apart');
        }
    }

    public function test_the_php_language_files_say_tu_in_spanish_and_sie_in_german(): void
    {
        $formal = [];
        foreach ($this->phpLines('es') as $key => $line) {
            if (! str_starts_with($key, 'validation.custom.') && ($word = $this->spanishFormal($line)) !== null) {
                $formal[] = 'es '.$key.'  ['.$word.']';
            }
        }
        $du = '/(?<!\p{L})(du|dich|dir|dein|deine|deinem|deinen|deiner|deines|versuche|klicke|warte|überprüfe|verwalte|prüfe|gib|lade)(?!\p{L})/iu';
        foreach ($this->phpLines('de') as $key => $line) {
            if (preg_match($du, $this->plain($line), $m)) {
                $formal[] = 'de '.$key.'  ['.$m[1].']';
            }
        }

        $this->assertSame([], $formal, 'the PHP language files address the reader the way their language files do');
    }

    public function test_every_language_with_two_forms_of_you_uses_the_one_most_of_its_texts_use(): void
    {
        $mixed = [];
        foreach (self::MINORITY as $locale => $pattern) {
            [$json, $js, $required, $requiredJs] = $this->texts($locale);
            $texts = [];
            foreach (array_keys($required) as $key) {
                if (is_string($json[$key] ?? null)) {
                    $texts[$key] = $json[$key];
                }
            }
            foreach (array_keys($requiredJs) as $key) {
                if (is_string($js[$key] ?? null)) {
                    $texts['js: '.$key] = $js[$key];
                }
            }
            foreach ($this->phpLines($locale) as $key => $line) {
                $texts['php: '.$key] = $line;
            }
            foreach ($this->menuTitles() as $title) {
                if (is_string($json[$title] ?? null)) {
                    $texts['menu: '.$title] = $json[$title];
                }
            }
            $this->assertGreaterThan(8000, count($texts), $locale);

            foreach ($texts as $key => $value) {
                if (in_array($key, self::NOT_AN_ADDRESS[$locale] ?? [], true)) {
                    continue;
                }
                if (preg_match($pattern, $this->plain($value), $m)) {
                    $mixed[] = $locale.' '.$key.'  ['.$m[0].']';
                }
            }
        }

        $this->assertSame([], $mixed, 'a text uses the form of "you" its language does not use');
    }
}
