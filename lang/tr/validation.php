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

    'accepted' => ':Attribute alanı kabul edilmelidir.',
    'accepted_if' => ':other alanı :value olduğunda :attribute alanı kabul edilmelidir.',
    'active_url' => ':Attribute alanı geçerli bir web adresi değil.',
    'after' => ':Attribute alanı :date tarihinden sonraki bir tarih olmalıdır.',
    'after_or_equal' => ':Attribute alanı :date tarihi veya sonrası olmalıdır.',
    'alpha' => ':Attribute alanı yalnızca harf içerebilir.',
    'alpha_dash' => ':Attribute alanı yalnızca harf, rakam, tire ve alt çizgi içerebilir.',
    'alpha_num' => ':Attribute alanı yalnızca harf ve rakam içerebilir.',
    'array' => ':Attribute alanı bir liste olmalıdır.',
    'before' => ':Attribute alanı :date tarihinden önceki bir tarih olmalıdır.',
    'before_or_equal' => ':Attribute alanı :date tarihi veya öncesi olmalıdır.',
    'between' => [
        'array' => ':Attribute alanında :min ile :max arasında öğe olmalıdır.',
        'file' => ':Attribute dosyasının boyutu :min ile :max kilobayt arasında olmalıdır.',
        'numeric' => ':Attribute alanı :min ile :max arasında olmalıdır.',
        'string' => ':Attribute alanı :min ile :max karakter arasında olmalıdır.',
    ],
    'boolean' => ':Attribute alanı evet veya hayır olmalıdır.',
    'confirmed' => ':Attribute alanı onayıyla eşleşmiyor.',
    'current_password' => 'Şifre yanlış.',
    'date' => ':Attribute alanı geçerli bir tarih değil.',
    'date_equals' => ':Attribute alanı :date tarihi olmalıdır.',
    'date_format' => ':Attribute alanı :format biçimine uymuyor.',
    'declined' => ':Attribute alanı reddedilmelidir.',
    'declined_if' => ':other alanı :value olduğunda :attribute alanı reddedilmelidir.',
    'different' => ':Attribute ve :other alanları farklı olmalıdır.',
    'digits' => ':Attribute alanı :digits haneli olmalıdır.',
    'digits_between' => ':Attribute alanı :min ile :max hane arasında olmalıdır.',
    'dimensions' => ':Attribute görselinin boyutları uygun değil.',
    'distinct' => ':Attribute alanında tekrar eden bir değer var.',
    'doesnt_end_with' => ':Attribute alanı şu değerlerden biriyle bitmemelidir: :values.',
    'doesnt_start_with' => ':Attribute alanı şu değerlerden biriyle başlamamalıdır: :values.',
    'email' => 'Geçerli bir e-posta adresi girin, örneğin name@example.com.',
    'ends_with' => ':Attribute alanı şu değerlerden biriyle bitmelidir: :values.',
    'enum' => ':Attribute için seçilen değer geçersiz.',
    'exists' => ':Attribute için seçilen değer bulunamadı.',
    'file' => ':Attribute alanı bir dosya olmalıdır.',
    'filled' => ':Attribute alanı boş bırakılamaz.',
    'gt' => [
        'array' => ':Attribute alanında :value öğeden fazla olmalıdır.',
        'file' => ':Attribute dosyasının boyutu :value kilobayttan büyük olmalıdır.',
        'numeric' => ':Attribute alanı :value değerinden büyük olmalıdır.',
        'string' => ':Attribute alanı :value karakterden uzun olmalıdır.',
    ],
    'gte' => [
        'array' => ':Attribute alanında en az :value öğe olmalıdır.',
        'file' => ':Attribute dosyasının boyutu en az :value kilobayt olmalıdır.',
        'numeric' => ':Attribute alanı en az :value olmalıdır.',
        'string' => ':Attribute alanı en az :value karakter olmalıdır.',
    ],
    'image' => ':Attribute alanı bir görsel olmalıdır.',
    'in' => ':Attribute için seçilen değer geçersiz.',
    'in_array' => ':Attribute alanı :other alanının değerlerinden biri olmalıdır.',
    'integer' => ':Attribute alanı bir tam sayı olmalıdır.',
    'ip' => ':Attribute alanı geçerli bir IP adresi olmalıdır.',
    'ipv4' => ':Attribute alanı geçerli bir IPv4 adresi olmalıdır.',
    'ipv6' => ':Attribute alanı geçerli bir IPv6 adresi olmalıdır.',
    'json' => ':Attribute alanı geçerli bir JSON metni olmalıdır.',
    'lt' => [
        'array' => ':Attribute alanında :value öğeden az olmalıdır.',
        'file' => ':Attribute dosyasının boyutu :value kilobayttan küçük olmalıdır.',
        'numeric' => ':Attribute alanı :value değerinden küçük olmalıdır.',
        'string' => ':Attribute alanı :value karakterden kısa olmalıdır.',
    ],
    'lte' => [
        'array' => ':Attribute alanında en fazla :value öğe olabilir.',
        'file' => ':Attribute dosyasının boyutu en fazla :value kilobayt olabilir.',
        'numeric' => ':Attribute alanı en fazla :value olabilir.',
        'string' => ':Attribute alanı en fazla :value karakter olabilir.',
    ],
    'mac_address' => ':Attribute alanı geçerli bir MAC adresi olmalıdır.',
    'max' => [
        'array' => ':Attribute alanında en fazla :max öğe olabilir.',
        'file' => ':Attribute dosyasının boyutu en fazla :max kilobayt olabilir.',
        'numeric' => ':Attribute alanı en fazla :max olabilir.',
        'string' => ':Attribute alanı en fazla :max karakter olabilir.',
    ],
    'max_digits' => ':Attribute alanı en fazla :max haneli olabilir.',
    'mimes' => ':Attribute şu türlerden bir dosya olmalıdır: :values.',
    'mimetypes' => ':Attribute şu türlerden bir dosya olmalıdır: :values.',
    'min' => [
        'array' => ':Attribute alanında en az :min öğe olmalıdır.',
        'file' => ':Attribute dosyasının boyutu en az :min kilobayt olmalıdır.',
        'numeric' => ':Attribute alanı en az :min olmalıdır.',
        'string' => ':Attribute alanı en az :min karakter olmalıdır.',
    ],
    'min_digits' => ':Attribute alanı en az :min haneli olmalıdır.',
    'multiple_of' => ':Attribute alanı :value değerinin katı olmalıdır.',
    'not_in' => ':Attribute için seçilen değer geçersiz.',
    'not_regex' => ':Attribute alanının biçimi geçersiz.',
    'numeric' => ':Attribute alanı bir sayı olmalıdır.',
    'password' => [
        'letters' => ':Attribute alanı en az bir harf içermelidir.',
        'mixed' => ':Attribute alanı en az bir büyük ve bir küçük harf içermelidir.',
        'numbers' => ':Attribute alanı en az bir rakam içermelidir.',
        'symbols' => ':Attribute alanı en az bir sembol içermelidir.',
        'uncompromised' => ':Attribute bir veri sızıntısında görüldü. Başka bir tane seçin.',
    ],
    'present' => ':Attribute alanı bulunmalıdır.',
    'prohibited' => ':Attribute alanı doldurulamaz.',
    'prohibited_if' => ':other alanı :value olduğunda :attribute alanı doldurulamaz.',
    'prohibited_unless' => ':Attribute alanı yalnızca :other alanı şu değerlerden biri olduğunda doldurulabilir: :values.',
    'prohibits' => ':Attribute alanı :other alanıyla birlikte doldurulamaz.',
    'regex' => ':Attribute alanının biçimi geçersiz.',
    'required' => ':Attribute alanı zorunludur.',
    'required_array_keys' => ':Attribute alanı şu değerleri içermelidir: :values.',
    'required_if' => ':other alanı :value olduğunda :attribute alanı zorunludur.',
    'required_if_accepted' => ':other kabul edildiğinde :attribute alanı zorunludur.',
    'required_unless' => ':other alanı şu değerlerden biri değilse :attribute alanı zorunludur: :values.',
    'required_with' => ':values doldurulduğunda :attribute alanı zorunludur.',
    'required_with_all' => ':values alanlarının hepsi doldurulduğunda :attribute alanı zorunludur.',
    'required_without' => ':values doldurulmadığında :attribute alanı zorunludur.',
    'required_without_all' => 'Şu alanların hiçbiri doldurulmadığında :attribute alanı zorunludur: :values.',
    'same' => ':Attribute ve :other alanları aynı olmalıdır.',
    'size' => [
        'array' => ':Attribute alanında :size öğe olmalıdır.',
        'file' => ':Attribute dosyasının boyutu :size kilobayt olmalıdır.',
        'numeric' => ':Attribute alanı :size olmalıdır.',
        'string' => ':Attribute alanı :size karakter olmalıdır.',
    ],
    'starts_with' => ':Attribute alanı şu değerlerden biriyle başlamalıdır: :values.',
    'string' => ':Attribute alanı metin olmalıdır.',
    'timezone' => ':Attribute alanı geçerli bir saat dilimi olmalıdır.',
    'unique' => ':Attribute zaten kullanılıyor.',
    'uploaded' => ':Attribute yüklenemedi.',
    'url' => ':Attribute alanı geçerli bir web adresi olmalıdır.',
    'uuid' => ':Attribute alanı geçerli bir UUID olmalıdır.',
    'phone' => ':Attribute alanı geçerli bir telefon numarası olmalıdır.',

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
            'exists' => 'Bu içerik artık kullanılamıyor.',
        ],
        'parent_id' => [
            'exists' => 'Yanıtladığınız yorum artık kullanılamıyor.',
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
        'username' => 'Kullanıcı adı',
        'email' => 'E-posta',
        'password' => 'Şifre',
        'password_confirmation' => 'Şifre doğrulaması',
        'cgu' => 'Hizmet şartları',
        'title' => 'Başlık',
        'description' => 'Açıklama',
        'category_id' => 'Kategori',
        'language' => 'Dil',
        'name' => 'İsim',
        'message' => 'Mesaj',
        'avatar' => 'Profil resmi',
        'banner' => 'Afiş',
        'first_name' => 'Ad',
        'last_name' => 'Soyad',
        'city' => 'Şehir',
        'country' => 'Ülke',
        'address' => 'Adres',
        'phone' => 'Telefon',
        'mobile' => 'Cep telefonu',
        'age' => 'Yaş',
        'sex' => 'Cinsiyet',
        'gender' => 'Cinsiyet',
        'day' => 'Gün',
        'month' => 'Ay',
        'year' => 'Yıl',
        'hour' => 'Saat',
        'minute' => 'Dakika',
        'second' => 'Saniye',
        'content' => 'İçerik',
        'excerpt' => 'Özet',
        'date' => 'Tarih',
        'time' => 'Zaman',
        'available' => 'Kullanılabilirlik',
        'size' => 'Boyut',
        'comment' => 'Yorum',
        'reason' => 'Neden',
        'subject' => 'Konu',
        'body' => 'İleti metni',
        'to' => 'Alıcı',
        'slug' => 'Kısa isim',
        'meta_title' => 'Meta başlığı',
        'meta_description' => 'Meta açıklaması',
        'sort_order' => 'Sıralama',
        'price' => 'Fiyat',
        'duration' => 'Süre',
        'days' => 'Gün sayısı',
        'ip_address' => 'IP adresi',
        'image_url' => 'Görsel adresi',
        'link_url' => 'Bağlantı',
        'starts_at' => 'Başlangıç',
        'expires_at' => 'Bitiş',
        'source' => 'Kaynak adres',
        'destination' => 'Hedef adres',
        'icon' => 'Simge',
        'file' => 'Dosya',
        'label' => 'Görünen ad',
        'code' => 'Kod',
        'website' => 'Web sitesi',
        'user_id' => 'Üye numarası',
        'video_id' => 'Video numarası',
        'thumbnail_file' => 'Küçük resim',
        'thumbnail' => 'Küçük resim',
        'pages' => 'Sayfa sayısı',
        'start_page' => 'Başlangıç sayfası',
        'pagesize' => 'Sayfa başına video',
        'delay' => 'Bekleme süresi',
        'updated_hours' => 'Güncellemeden bu yana saat',
    ],

];
