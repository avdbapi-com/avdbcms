<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The default error messages of the validator, in plain words (R1-121 for English, Russian and Arabic, R2A-049 for
    | every other language). :attribute is the field's name: the form's own label where the form names its fields
    | (Account settings), else the name below under "attributes". Placeholders stay exactly as they are. Where a
    | language writes the name without quotes and has capital letters, the names below are in lower case and a
    | sentence that starts with the name uses :Attribute (the validator writes its first letter as a capital).
    |
    */

    'accepted' => 'Das Feld „:attribute“ muss akzeptiert werden.',
    'accepted_if' => 'Das Feld „:attribute“ muss akzeptiert werden, wenn „:other“ :value ist.',
    'active_url' => 'Das Feld „:attribute“ ist keine gültige Webadresse.',
    'after' => 'Das Feld „:attribute“ muss ein Datum nach :date sein.',
    'after_or_equal' => 'Das Feld „:attribute“ muss :date oder ein späteres Datum sein.',
    'alpha' => 'Das Feld „:attribute“ darf nur Buchstaben enthalten.',
    'alpha_dash' => 'Das Feld „:attribute“ darf nur Buchstaben, Zahlen, Bindestriche und Unterstriche enthalten.',
    'alpha_num' => 'Das Feld „:attribute“ darf nur Buchstaben und Zahlen enthalten.',
    'array' => 'Das Feld „:attribute“ muss eine Liste sein.',
    'before' => 'Das Feld „:attribute“ muss ein Datum vor :date sein.',
    'before_or_equal' => 'Das Feld „:attribute“ muss :date oder ein früheres Datum sein.',
    'between' => [
        'array' => 'Das Feld „:attribute“ muss zwischen :min und :max Einträge haben.',
        'file' => 'Die Datei „:attribute“ muss zwischen :min und :max Kilobyte groß sein.',
        'numeric' => 'Das Feld „:attribute“ muss zwischen :min und :max liegen.',
        'string' => 'Das Feld „:attribute“ muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => 'Das Feld „:attribute“ muss Ja oder Nein sein.',
    'confirmed' => 'Das Feld „:attribute“ stimmt nicht mit der Bestätigung überein.',
    'current_password' => 'Das Passwort ist falsch.',
    'date' => 'Das Feld „:attribute“ ist kein gültiges Datum.',
    'date_equals' => 'Das Feld „:attribute“ muss das Datum :date sein.',
    'date_format' => 'Das Feld „:attribute“ entspricht nicht dem Format :format.',
    'declined' => 'Das Feld „:attribute“ muss abgelehnt werden.',
    'declined_if' => 'Das Feld „:attribute“ muss abgelehnt werden, wenn „:other“ :value ist.',
    'different' => 'Die Felder „:attribute“ und „:other“ müssen sich unterscheiden.',
    'digits' => 'Das Feld „:attribute“ muss :digits Ziffern haben.',
    'digits_between' => 'Das Feld „:attribute“ muss zwischen :min und :max Ziffern haben.',
    'dimensions' => 'Das Bild „:attribute“ hat nicht die passende Größe.',
    'distinct' => 'Das Feld „:attribute“ enthält einen Wert doppelt.',
    'doesnt_end_with' => 'Das Feld „:attribute“ darf nicht mit einem dieser Werte enden: :values.',
    'doesnt_start_with' => 'Das Feld „:attribute“ darf nicht mit einem dieser Werte beginnen: :values.',
    'email' => 'Geben Sie eine gültige E-Mail-Adresse ein, z. B. name@example.com.',
    'ends_with' => 'Das Feld „:attribute“ muss mit einem dieser Werte enden: :values.',
    'enum' => 'Der gewählte Wert für „:attribute“ ist ungültig.',
    'exists' => 'Der gewählte Wert für „:attribute“ wurde nicht gefunden.',
    'file' => 'Das Feld „:attribute“ muss eine Datei sein.',
    'filled' => 'Das Feld „:attribute“ darf nicht leer sein.',
    'gt' => [
        'array' => 'Das Feld „:attribute“ muss mehr als :value Einträge haben.',
        'file' => 'Die Datei „:attribute“ muss größer als :value Kilobyte sein.',
        'numeric' => 'Das Feld „:attribute“ muss größer als :value sein.',
        'string' => 'Das Feld „:attribute“ muss länger als :value Zeichen sein.',
    ],
    'gte' => [
        'array' => 'Das Feld „:attribute“ muss mindestens :value Einträge haben.',
        'file' => 'Die Datei „:attribute“ muss mindestens :value Kilobyte groß sein.',
        'numeric' => 'Das Feld „:attribute“ muss mindestens :value sein.',
        'string' => 'Das Feld „:attribute“ muss mindestens :value Zeichen lang sein.',
    ],
    'image' => 'Das Feld „:attribute“ muss ein Bild sein.',
    'in' => 'Der gewählte Wert für „:attribute“ ist ungültig.',
    'in_array' => 'Das Feld „:attribute“ muss einer der Werte von „:other“ sein.',
    'integer' => 'Das Feld „:attribute“ muss eine ganze Zahl sein.',
    'ip' => 'Das Feld „:attribute“ muss eine gültige IP-Adresse sein.',
    'ipv4' => 'Das Feld „:attribute“ muss eine gültige IPv4-Adresse sein.',
    'ipv6' => 'Das Feld „:attribute“ muss eine gültige IPv6-Adresse sein.',
    'json' => 'Das Feld „:attribute“ muss gültiger JSON-Text sein.',
    'lt' => [
        'array' => 'Das Feld „:attribute“ muss weniger als :value Einträge haben.',
        'file' => 'Die Datei „:attribute“ muss kleiner als :value Kilobyte sein.',
        'numeric' => 'Das Feld „:attribute“ muss kleiner als :value sein.',
        'string' => 'Das Feld „:attribute“ muss kürzer als :value Zeichen sein.',
    ],
    'lte' => [
        'array' => 'Das Feld „:attribute“ darf höchstens :value Einträge haben.',
        'file' => 'Die Datei „:attribute“ darf höchstens :value Kilobyte groß sein.',
        'numeric' => 'Das Feld „:attribute“ darf höchstens :value sein.',
        'string' => 'Das Feld „:attribute“ darf höchstens :value Zeichen lang sein.',
    ],
    'mac_address' => 'Das Feld „:attribute“ muss eine gültige MAC-Adresse sein.',
    'max' => [
        'array' => 'Das Feld „:attribute“ darf höchstens :max Einträge haben.',
        'file' => 'Die Datei „:attribute“ darf höchstens :max Kilobyte groß sein.',
        'numeric' => 'Das Feld „:attribute“ darf höchstens :max sein.',
        'string' => 'Das Feld „:attribute“ darf höchstens :max Zeichen lang sein.',
    ],
    'max_digits' => 'Das Feld „:attribute“ darf höchstens :max Ziffern haben.',
    'mimes' => 'Die Datei „:attribute“ muss eine dieser Arten sein: :values.',
    'mimetypes' => 'Die Datei „:attribute“ muss eine dieser Arten sein: :values.',
    'min' => [
        'array' => 'Das Feld „:attribute“ muss mindestens :min Einträge haben.',
        'file' => 'Die Datei „:attribute“ muss mindestens :min Kilobyte groß sein.',
        'numeric' => 'Das Feld „:attribute“ muss mindestens :min sein.',
        'string' => 'Das Feld „:attribute“ muss mindestens :min Zeichen lang sein.',
    ],
    'min_digits' => 'Das Feld „:attribute“ muss mindestens :min Ziffern haben.',
    'multiple_of' => 'Das Feld „:attribute“ muss ein Vielfaches von :value sein.',
    'not_in' => 'Der gewählte Wert für „:attribute“ ist ungültig.',
    'not_regex' => 'Das Format von „:attribute“ ist ungültig.',
    'numeric' => 'Das Feld „:attribute“ muss eine Zahl sein.',
    'password' => [
        'letters' => 'Das Feld „:attribute“ muss mindestens einen Buchstaben enthalten.',
        'mixed' => 'Das Feld „:attribute“ muss mindestens einen Groß- und einen Kleinbuchstaben enthalten.',
        'numbers' => 'Das Feld „:attribute“ muss mindestens eine Ziffer enthalten.',
        'symbols' => 'Das Feld „:attribute“ muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => 'Der Wert von „:attribute“ ist in einem Datenleck aufgetaucht. Wählen Sie bitte einen anderen.',
    ],
    'present' => 'Das Feld „:attribute“ muss vorhanden sein.',
    'prohibited' => 'Das Feld „:attribute“ ist nicht erlaubt.',
    'prohibited_if' => 'Das Feld „:attribute“ ist nicht erlaubt, wenn „:other“ :value ist.',
    'prohibited_unless' => 'Das Feld „:attribute“ ist nur erlaubt, wenn „:other“ einer dieser Werte ist: :values.',
    'prohibits' => 'Das Feld „:attribute“ darf nicht zusammen mit „:other“ ausgefüllt werden.',
    'regex' => 'Das Format von „:attribute“ ist ungültig.',
    'required' => 'Das Feld „:attribute“ ist erforderlich.',
    'required_array_keys' => 'Das Feld „:attribute“ muss Einträge für diese Werte haben: :values.',
    'required_if' => 'Das Feld „:attribute“ ist erforderlich, wenn „:other“ :value ist.',
    'required_if_accepted' => 'Das Feld „:attribute“ ist erforderlich, wenn „:other“ akzeptiert ist.',
    'required_unless' => 'Das Feld „:attribute“ ist erforderlich, außer „:other“ ist einer dieser Werte: :values.',
    'required_with' => 'Das Feld „:attribute“ ist erforderlich, wenn :values ausgefüllt ist.',
    'required_with_all' => 'Das Feld „:attribute“ ist erforderlich, wenn :values ausgefüllt sind.',
    'required_without' => 'Das Feld „:attribute“ ist erforderlich, wenn :values nicht ausgefüllt ist.',
    'required_without_all' => 'Das Feld „:attribute“ ist erforderlich, wenn keines dieser Felder ausgefüllt ist: :values.',
    'same' => 'Die Felder „:attribute“ und „:other“ müssen übereinstimmen.',
    'size' => [
        'array' => 'Das Feld „:attribute“ muss :size Einträge haben.',
        'file' => 'Die Datei „:attribute“ muss :size Kilobyte groß sein.',
        'numeric' => 'Das Feld „:attribute“ muss :size sein.',
        'string' => 'Das Feld „:attribute“ muss :size Zeichen lang sein.',
    ],
    'starts_with' => 'Das Feld „:attribute“ muss mit einem dieser Werte beginnen: :values.',
    'string' => 'Das Feld „:attribute“ muss Text sein.',
    'timezone' => 'Das Feld „:attribute“ muss eine gültige Zeitzone sein.',
    'unique' => 'Dieser Wert für „:attribute“ ist bereits vergeben.',
    'uploaded' => 'Die Datei „:attribute“ konnte nicht hochgeladen werden.',
    'url' => 'Das Feld „:attribute“ muss eine gültige Webadresse sein.',
    'uuid' => 'Das Feld „:attribute“ muss eine gültige UUID sein.',
    'phone' => 'Das Feld „:attribute“ muss eine gültige Telefonnummer sein.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        // A like or dislike of something no longer shown (the report form has its own message), and a reply
        // to a comment no longer shown: the field itself is no word the reader knows.
        'id' => [
            'exists' => 'Dieser Inhalt ist nicht mehr verfügbar.',
        ],
        'parent_id' => [
            'exists' => 'Der Kommentar, auf den Sie antworten, ist nicht mehr verfügbar.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The names of the fields whose form does not name them itself, as the
    | forms show them.
    |
    */

    'attributes' => [
        'username' => 'Benutzername',
        'email' => 'E-Mail',
        'password' => 'Passwort',
        'password_confirmation' => 'Passwortbestätigung',
        'cgu' => 'Nutzungsbedingungen',
        'title' => 'Titel',
        'description' => 'Beschreibung',
        'category_id' => 'Kategorie',
        'language' => 'Sprache',
        'name' => 'Name',
        'message' => 'Nachricht',
        'avatar' => 'Profilbild',
        'banner' => 'Banner',
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'city' => 'Stadt',
        'country' => 'Land',
        'address' => 'Adresse',
        'phone' => 'Telefon',
        'mobile' => 'Mobiltelefon',
        'age' => 'Alter',
        'sex' => 'Geschlecht',
        'gender' => 'Geschlecht',
        'day' => 'Tag',
        'month' => 'Monat',
        'year' => 'Jahr',
        'hour' => 'Stunde',
        'minute' => 'Minute',
        'second' => 'Sekunde',
        'content' => 'Inhalt',
        'excerpt' => 'Auszug',
        'date' => 'Datum',
        'time' => 'Uhrzeit',
        'available' => 'Verfügbar',
        'size' => 'Größe',
        'comment' => 'Kommentar',
        'reason' => 'Grund',
        'subject' => 'Betreff',
        'body' => 'Nachrichtentext',
        'to' => 'Empfänger',
        'slug' => 'Slug',
        'meta_title' => 'Meta-Titel',
        'meta_description' => 'Meta-Beschreibung',
        'sort_order' => 'Reihenfolge',
        'price' => 'Preis',
        'duration' => 'Dauer',
        'days' => 'Anzahl der Tage',
        'ip_address' => 'IP-Adresse',
        'image_url' => 'Bildadresse',
        'link_url' => 'Link',
        'starts_at' => 'Beginn',
        'expires_at' => 'Ende',
        'source' => 'Quelladresse',
        'destination' => 'Zieladresse',
        'icon' => 'Symbol',
        'file' => 'Datei',
        'label' => 'Bezeichnung',
        'code' => 'Code',
        'website' => 'Website',
        'user_id' => 'Mitgliedsnummer',
        'video_id' => 'Videonummer',
        'thumbnail_file' => 'Vorschaubild',
        'thumbnail' => 'Vorschaubild',
        'pages' => 'Anzahl der Seiten',
        'start_page' => 'Erste Seite',
        'pagesize' => 'Videos pro Seite',
        'delay' => 'Verzögerung',
        'updated_hours' => 'Stunden seit der Aktualisierung',
    ],

];
