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

    'accepted' => 'Le champ « :attribute » doit être accepté.',
    'accepted_if' => 'Le champ « :attribute » doit être accepté quand « :other » vaut :value.',
    'active_url' => 'Le champ « :attribute » n\'est pas une adresse web valide.',
    'after' => 'Le champ « :attribute » doit être une date après :date.',
    'after_or_equal' => 'Le champ « :attribute » doit être :date ou une date après.',
    'alpha' => 'Le champ « :attribute » ne peut contenir que des lettres.',
    'alpha_dash' => 'Le champ « :attribute » ne peut contenir que des lettres, des chiffres, des tirets et des tirets bas.',
    'alpha_num' => 'Le champ « :attribute » ne peut contenir que des lettres et des chiffres.',
    'array' => 'Le champ « :attribute » doit être une liste.',
    'before' => 'Le champ « :attribute » doit être une date avant :date.',
    'before_or_equal' => 'Le champ « :attribute » doit être :date ou une date avant.',
    'between' => [
        'array' => 'Le champ « :attribute » doit contenir entre :min et :max éléments.',
        'file' => 'Le fichier « :attribute » doit peser entre :min et :max kilo-octets.',
        'numeric' => 'Le champ « :attribute » doit être compris entre :min et :max.',
        'string' => 'Le champ « :attribute » doit contenir entre :min et :max caractères.',
    ],
    'boolean' => 'Le champ « :attribute » doit valoir oui ou non.',
    'confirmed' => 'Le champ « :attribute » ne correspond pas à sa confirmation.',
    'current_password' => 'Le mot de passe est incorrect.',
    'date' => 'Le champ « :attribute » n\'est pas une date valide.',
    'date_equals' => 'Le champ « :attribute » doit être la date :date.',
    'date_format' => 'Le champ « :attribute » ne suit pas le format :format.',
    'declined' => 'Le champ « :attribute » doit être refusé.',
    'declined_if' => 'Le champ « :attribute » doit être refusé quand « :other » vaut :value.',
    'different' => 'Les champs « :attribute » et « :other » doivent être différents.',
    'digits' => 'Le champ « :attribute » doit contenir :digits chiffres.',
    'digits_between' => 'Le champ « :attribute » doit contenir entre :min et :max chiffres.',
    'dimensions' => 'Les dimensions de l\'image « :attribute » ne conviennent pas.',
    'distinct' => 'Le champ « :attribute » contient une valeur en double.',
    'doesnt_end_with' => 'Le champ « :attribute » ne doit pas se terminer par l\'une de ces valeurs : :values.',
    'doesnt_start_with' => 'Le champ « :attribute » ne doit pas commencer par l\'une de ces valeurs : :values.',
    'email' => 'Saisissez une adresse e-mail valide, comme name@example.com.',
    'ends_with' => 'Le champ « :attribute » doit se terminer par l\'une de ces valeurs : :values.',
    'enum' => 'La valeur choisie pour « :attribute » n\'est pas valide.',
    'exists' => 'La valeur choisie pour « :attribute » est introuvable.',
    'file' => 'Le champ « :attribute » doit être un fichier.',
    'filled' => 'Le champ « :attribute » ne peut pas être vide.',
    'gt' => [
        'array' => 'Le champ « :attribute » doit contenir plus de :value éléments.',
        'file' => 'Le fichier « :attribute » doit peser plus de :value kilo-octets.',
        'numeric' => 'Le champ « :attribute » doit être supérieur à :value.',
        'string' => 'Le champ « :attribute » doit contenir plus de :value caractères.',
    ],
    'gte' => [
        'array' => 'Le champ « :attribute » doit contenir au moins :value éléments.',
        'file' => 'Le fichier « :attribute » doit peser au moins :value kilo-octets.',
        'numeric' => 'Le champ « :attribute » doit être supérieur ou égal à :value.',
        'string' => 'Le champ « :attribute » doit contenir au moins :value caractères.',
    ],
    'image' => 'Le champ « :attribute » doit être une image.',
    'in' => 'La valeur choisie pour « :attribute » n\'est pas valide.',
    'in_array' => 'Le champ « :attribute » doit être l\'une des valeurs de « :other ».',
    'integer' => 'Le champ « :attribute » doit être un nombre entier.',
    'ip' => 'Le champ « :attribute » doit être une adresse IP valide.',
    'ipv4' => 'Le champ « :attribute » doit être une adresse IPv4 valide.',
    'ipv6' => 'Le champ « :attribute » doit être une adresse IPv6 valide.',
    'json' => 'Le champ « :attribute » doit être un texte JSON valide.',
    'lt' => [
        'array' => 'Le champ « :attribute » doit contenir moins de :value éléments.',
        'file' => 'Le fichier « :attribute » doit peser moins de :value kilo-octets.',
        'numeric' => 'Le champ « :attribute » doit être inférieur à :value.',
        'string' => 'Le champ « :attribute » doit contenir moins de :value caractères.',
    ],
    'lte' => [
        'array' => 'Le champ « :attribute » ne doit pas contenir plus de :value éléments.',
        'file' => 'Le fichier « :attribute » ne doit pas peser plus de :value kilo-octets.',
        'numeric' => 'Le champ « :attribute » doit être inférieur ou égal à :value.',
        'string' => 'Le champ « :attribute » ne doit pas contenir plus de :value caractères.',
    ],
    'mac_address' => 'Le champ « :attribute » doit être une adresse MAC valide.',
    'max' => [
        'array' => 'Le champ « :attribute » ne doit pas contenir plus de :max éléments.',
        'file' => 'Le fichier « :attribute » ne doit pas peser plus de :max kilo-octets.',
        'numeric' => 'Le champ « :attribute » ne doit pas être supérieur à :max.',
        'string' => 'Le champ « :attribute » ne doit pas dépasser :max caractères.',
    ],
    'max_digits' => 'Le champ « :attribute » ne doit pas contenir plus de :max chiffres.',
    'mimes' => 'Le fichier « :attribute » doit être de type : :values.',
    'mimetypes' => 'Le fichier « :attribute » doit être de type : :values.',
    'min' => [
        'array' => 'Le champ « :attribute » doit contenir au moins :min éléments.',
        'file' => 'Le fichier « :attribute » doit peser au moins :min kilo-octets.',
        'numeric' => 'Le champ « :attribute » doit valoir au moins :min.',
        'string' => 'Le champ « :attribute » doit contenir au moins :min caractères.',
    ],
    'min_digits' => 'Le champ « :attribute » doit contenir au moins :min chiffres.',
    'multiple_of' => 'Le champ « :attribute » doit être un multiple de :value.',
    'not_in' => 'La valeur choisie pour « :attribute » n\'est pas valide.',
    'not_regex' => 'Le format du champ « :attribute » n\'est pas valide.',
    'numeric' => 'Le champ « :attribute » doit être un nombre.',
    'password' => [
        'letters' => 'Le champ « :attribute » doit contenir au moins une lettre.',
        'mixed' => 'Le champ « :attribute » doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ « :attribute » doit contenir au moins un chiffre.',
        'symbols' => 'Le champ « :attribute » doit contenir au moins un symbole.',
        'uncompromised' => 'La valeur du champ « :attribute » est apparue dans une fuite de données. Choisissez-en une autre.',
    ],
    'present' => 'Le champ « :attribute » doit être présent.',
    'prohibited' => 'Le champ « :attribute » n\'est pas autorisé.',
    'prohibited_if' => 'Le champ « :attribute » n\'est pas autorisé quand « :other » vaut :value.',
    'prohibited_unless' => 'Le champ « :attribute » n\'est autorisé que si « :other » vaut :values.',
    'prohibits' => 'Le champ « :attribute » ne peut pas être rempli avec « :other ».',
    'regex' => 'Le format du champ « :attribute » n\'est pas valide.',
    'required' => 'Le champ « :attribute » est obligatoire.',
    'required_array_keys' => 'Le champ « :attribute » doit contenir : :values.',
    'required_if' => 'Le champ « :attribute » est obligatoire quand « :other » vaut :value.',
    'required_if_accepted' => 'Le champ « :attribute » est obligatoire quand « :other » est accepté.',
    'required_unless' => 'Le champ « :attribute » est obligatoire sauf si « :other » vaut :values.',
    'required_with' => 'Le champ « :attribute » est obligatoire quand :values est rempli.',
    'required_with_all' => 'Le champ « :attribute » est obligatoire quand :values sont remplis.',
    'required_without' => 'Le champ « :attribute » est obligatoire quand :values n\'est pas rempli.',
    'required_without_all' => 'Le champ « :attribute » est obligatoire quand aucun de ces champs n\'est rempli : :values.',
    'same' => 'Les champs « :attribute » et « :other » doivent être identiques.',
    'size' => [
        'array' => 'Le champ « :attribute » doit contenir :size éléments.',
        'file' => 'Le fichier « :attribute » doit peser :size kilo-octets.',
        'numeric' => 'Le champ « :attribute » doit valoir :size.',
        'string' => 'Le champ « :attribute » doit contenir :size caractères.',
    ],
    'starts_with' => 'Le champ « :attribute » doit commencer par l\'une de ces valeurs : :values.',
    'string' => 'Le champ « :attribute » doit être du texte.',
    'timezone' => 'Le champ « :attribute » doit être un fuseau horaire valide.',
    'unique' => 'Cette valeur du champ « :attribute » est déjà utilisée.',
    'uploaded' => 'Le fichier « :attribute » n\'a pas pu être envoyé.',
    'url' => 'Le champ « :attribute » doit être une adresse web valide.',
    'uuid' => 'Le champ « :attribute » doit être un UUID valide.',
    'phone' => 'Le champ « :attribute » doit être un numéro de téléphone valide.',

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
            'exists' => 'Ce contenu n\'est plus disponible.',
        ],
        'parent_id' => [
            'exists' => 'Le commentaire auquel vous répondez n\'est plus disponible.',
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
        'username' => 'Nom d\'utilisateur',
        'email' => 'E-mail',
        'password' => 'Mot de passe',
        'password_confirmation' => 'Confirmation du mot de passe',
        'cgu' => 'Conditions d\'utilisation',
        'title' => 'Titre',
        'description' => 'Descriptif',
        'category_id' => 'Catégorie',
        'language' => 'Langue',
        'name' => 'Nom',
        'message' => 'Message',
        'avatar' => 'Photo de profil',
        'banner' => 'Bannière',
        'first_name' => 'Prénom',
        'last_name' => 'Nom de famille',
        'city' => 'Ville',
        'country' => 'Pays',
        'address' => 'Adresse',
        'phone' => 'Téléphone',
        'mobile' => 'Portable',
        'age' => 'Âge',
        'sex' => 'Sexe',
        'gender' => 'Genre',
        'day' => 'Jour',
        'month' => 'Mois',
        'year' => 'Année',
        'hour' => 'Heure',
        'minute' => 'Minute',
        'second' => 'Seconde',
        'content' => 'Contenu',
        'excerpt' => 'Extrait',
        'date' => 'Date',
        'time' => 'Heure',
        'available' => 'Disponible',
        'size' => 'Taille',
        'comment' => 'Commentaire',
        'reason' => 'Motif',
        'subject' => 'Objet',
        'body' => 'Corps du message',
        'to' => 'Destinataire',
        'slug' => 'Slug',
        'meta_title' => 'Méta-titre',
        'meta_description' => 'Méta-description',
        'sort_order' => 'Ordre de tri',
        'price' => 'Prix',
        'duration' => 'Durée',
        'days' => 'Nombre de jours',
        'ip_address' => 'Adresse IP',
        'image_url' => 'Adresse de l\'image',
        'link_url' => 'Lien',
        'starts_at' => 'Début',
        'expires_at' => 'Fin',
        'source' => 'Adresse d\'origine',
        'destination' => 'Adresse de destination',
        'icon' => 'Icône',
        'file' => 'Fichier',
        'label' => 'Libellé',
        'code' => 'Code',
        'website' => 'Site internet',
        'user_id' => 'Numéro du membre',
        'video_id' => 'Numéro de la vidéo',
        'thumbnail_file' => 'Miniature',
        'thumbnail' => 'Miniature',
        'pages' => 'Nombre de pages',
        'start_page' => 'Page de départ',
        'pagesize' => 'Vidéos par page',
        'delay' => 'Délai',
        'updated_hours' => 'Heures depuis la mise à jour',
    ],

];
