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

    'accepted' => ':Attribute mesti diterima.',
    'accepted_if' => ':Attribute mesti diterima apabila :other ialah :value.',
    'active_url' => ':Attribute bukan alamat web yang sah.',
    'after' => ':Attribute mesti tarikh selepas :date.',
    'after_or_equal' => ':Attribute mesti tarikh :date atau selepasnya.',
    'alpha' => ':Attribute hanya boleh mengandungi huruf.',
    'alpha_dash' => ':Attribute hanya boleh mengandungi huruf, nombor, sengkang dan garis bawah.',
    'alpha_num' => ':Attribute hanya boleh mengandungi huruf dan nombor.',
    'array' => ':Attribute mesti senarai.',
    'before' => ':Attribute mesti tarikh sebelum :date.',
    'before_or_equal' => ':Attribute mesti tarikh :date atau sebelumnya.',
    'between' => [
        'array' => ':Attribute mesti mempunyai antara :min hingga :max item.',
        'file' => 'Saiz fail :attribute mesti antara :min hingga :max kilobait.',
        'numeric' => ':Attribute mesti antara :min hingga :max.',
        'string' => ':Attribute mesti mempunyai antara :min hingga :max aksara.',
    ],
    'boolean' => ':Attribute mesti ya atau tidak.',
    'confirmed' => ':Attribute tidak sepadan dengan pengesahannya.',
    'current_password' => 'Kata laluan salah.',
    'date' => ':Attribute bukan tarikh yang sah.',
    'date_equals' => ':Attribute mesti tarikh :date.',
    'date_format' => ':Attribute tidak sepadan dengan format :format.',
    'declined' => ':Attribute mesti ditolak.',
    'declined_if' => ':Attribute mesti ditolak apabila :other ialah :value.',
    'different' => ':Attribute dan :other mesti berbeza.',
    'digits' => ':Attribute mesti mempunyai :digits digit.',
    'digits_between' => ':Attribute mesti mempunyai antara :min hingga :max digit.',
    'dimensions' => 'Saiz imej :attribute tidak sesuai.',
    'distinct' => ':Attribute mempunyai nilai berulang.',
    'doesnt_end_with' => ':Attribute tidak boleh berakhir dengan salah satu nilai ini: :values.',
    'doesnt_start_with' => ':Attribute tidak boleh bermula dengan salah satu nilai ini: :values.',
    'email' => 'Masukkan alamat e-mel yang sah, seperti name@example.com.',
    'ends_with' => ':Attribute mesti berakhir dengan salah satu nilai ini: :values.',
    'enum' => 'Nilai yang dipilih untuk :attribute tidak sah.',
    'exists' => 'Nilai yang dipilih untuk :attribute tidak ditemui.',
    'file' => ':Attribute mesti fail.',
    'filled' => ':Attribute tidak boleh kosong.',
    'gt' => [
        'array' => ':Attribute mesti mempunyai lebih daripada :value item.',
        'file' => 'Saiz fail :attribute mesti lebih daripada :value kilobait.',
        'numeric' => ':Attribute mesti lebih besar daripada :value.',
        'string' => ':Attribute mesti mempunyai lebih daripada :value aksara.',
    ],
    'gte' => [
        'array' => ':Attribute mesti mempunyai sekurang-kurangnya :value item.',
        'file' => 'Saiz fail :attribute mesti sekurang-kurangnya :value kilobait.',
        'numeric' => ':Attribute mesti sekurang-kurangnya :value.',
        'string' => ':Attribute mesti mempunyai sekurang-kurangnya :value aksara.',
    ],
    'image' => ':Attribute mesti imej.',
    'in' => 'Nilai yang dipilih untuk :attribute tidak sah.',
    'in_array' => ':Attribute mesti salah satu nilai :other.',
    'integer' => ':Attribute mesti nombor bulat.',
    'ip' => ':Attribute mesti alamat IP yang sah.',
    'ipv4' => ':Attribute mesti alamat IPv4 yang sah.',
    'ipv6' => ':Attribute mesti alamat IPv6 yang sah.',
    'json' => ':Attribute mesti teks JSON yang sah.',
    'lt' => [
        'array' => ':Attribute mesti mempunyai kurang daripada :value item.',
        'file' => 'Saiz fail :attribute mesti kurang daripada :value kilobait.',
        'numeric' => ':Attribute mesti kurang daripada :value.',
        'string' => ':Attribute mesti mempunyai kurang daripada :value aksara.',
    ],
    'lte' => [
        'array' => ':Attribute tidak boleh mempunyai lebih daripada :value item.',
        'file' => 'Saiz fail :attribute tidak boleh melebihi :value kilobait.',
        'numeric' => ':Attribute tidak boleh melebihi :value.',
        'string' => ':Attribute tidak boleh melebihi :value aksara.',
    ],
    'mac_address' => ':Attribute mesti alamat MAC yang sah.',
    'max' => [
        'array' => ':Attribute tidak boleh mempunyai lebih daripada :max item.',
        'file' => 'Saiz fail :attribute tidak boleh melebihi :max kilobait.',
        'numeric' => ':Attribute tidak boleh melebihi :max.',
        'string' => ':Attribute tidak boleh melebihi :max aksara.',
    ],
    'max_digits' => ':Attribute tidak boleh melebihi :max digit.',
    'mimes' => ':Attribute mesti fail jenis: :values.',
    'mimetypes' => ':Attribute mesti fail jenis: :values.',
    'min' => [
        'array' => ':Attribute mesti mempunyai sekurang-kurangnya :min item.',
        'file' => 'Saiz fail :attribute mesti sekurang-kurangnya :min kilobait.',
        'numeric' => ':Attribute mesti sekurang-kurangnya :min.',
        'string' => ':Attribute mesti mempunyai sekurang-kurangnya :min aksara.',
    ],
    'min_digits' => ':Attribute mesti mempunyai sekurang-kurangnya :min digit.',
    'multiple_of' => ':Attribute mesti gandaan :value.',
    'not_in' => 'Nilai yang dipilih untuk :attribute tidak sah.',
    'not_regex' => 'Format :attribute tidak sah.',
    'numeric' => ':Attribute mesti nombor.',
    'password' => [
        'letters' => ':Attribute mesti mengandungi sekurang-kurangnya satu huruf.',
        'mixed' => ':Attribute mesti mengandungi sekurang-kurangnya satu huruf besar dan satu huruf kecil.',
        'numbers' => ':Attribute mesti mengandungi sekurang-kurangnya satu nombor.',
        'symbols' => ':Attribute mesti mengandungi sekurang-kurangnya satu simbol.',
        'uncompromised' => ':Attribute ini pernah muncul dalam kebocoran data. Pilih yang lain.',
    ],
    'present' => ':Attribute mesti ada.',
    'prohibited' => ':Attribute tidak dibenarkan.',
    'prohibited_if' => ':Attribute tidak dibenarkan apabila :other ialah :value.',
    'prohibited_unless' => ':Attribute hanya dibenarkan apabila :other ialah salah satu daripada: :values.',
    'prohibits' => ':Attribute tidak boleh diisi bersama :other.',
    'regex' => 'Format :attribute tidak sah.',
    'required' => ':Attribute diperlukan.',
    'required_array_keys' => ':Attribute mesti mengandungi nilai untuk: :values.',
    'required_if' => ':Attribute diperlukan apabila :other ialah :value.',
    'required_if_accepted' => ':Attribute diperlukan apabila :other diterima.',
    'required_unless' => ':Attribute diperlukan kecuali :other ialah salah satu daripada: :values.',
    'required_with' => ':Attribute diperlukan apabila :values diisi.',
    'required_with_all' => ':Attribute diperlukan apabila :values semuanya diisi.',
    'required_without' => ':Attribute diperlukan apabila :values tidak diisi.',
    'required_without_all' => ':Attribute diperlukan apabila tiada satu pun yang diisi daripada: :values.',
    'same' => ':Attribute dan :other mesti sama.',
    'size' => [
        'array' => ':Attribute mesti mempunyai :size item.',
        'file' => 'Saiz fail :attribute mesti :size kilobait.',
        'numeric' => ':Attribute mesti :size.',
        'string' => ':Attribute mesti mempunyai :size aksara.',
    ],
    'starts_with' => ':Attribute mesti bermula dengan salah satu nilai ini: :values.',
    'string' => ':Attribute mesti teks.',
    'timezone' => ':Attribute mesti zon waktu yang sah.',
    'unique' => ':Attribute ini sudah digunakan.',
    'uploaded' => ':Attribute gagal dimuat naik.',
    'url' => ':Attribute mesti alamat web yang sah.',
    'uuid' => ':Attribute mesti UUID yang sah.',
    'phone' => ':Attribute mesti nombor telefon yang sah.',

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
            'exists' => 'Kandungan ini tidak lagi tersedia.',
        ],
        'parent_id' => [
            'exists' => 'Komen yang anda balas tidak lagi tersedia.',
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
        'username' => 'nama pengguna',
        'email' => 'e-mel',
        'password' => 'kata laluan',
        'password_confirmation' => 'pengesahan kata laluan',
        'cgu' => 'syarat perkhidmatan',
        'title' => 'tajuk',
        'description' => 'penerangan',
        'category_id' => 'kategori',
        'language' => 'bahasa',
        'name' => 'nama',
        'message' => 'mesej',
        'avatar' => 'gambar profil',
        'banner' => 'sepanduk',
        'first_name' => 'nama pertama',
        'last_name' => 'nama keluarga',
        'city' => 'bandar',
        'country' => 'negara',
        'address' => 'alamat',
        'phone' => 'telefon',
        'mobile' => 'nombor telefon bimbit',
        'age' => 'umur',
        'sex' => 'jantina',
        'gender' => 'jantina',
        'day' => 'hari',
        'month' => 'bulan',
        'year' => 'tahun',
        'hour' => 'jam',
        'minute' => 'minit',
        'second' => 'saat',
        'content' => 'kandungan',
        'excerpt' => 'petikan',
        'date' => 'tarikh',
        'time' => 'masa',
        'available' => 'ketersediaan',
        'size' => 'saiz',
        'comment' => 'komen',
        'reason' => 'sebab',
        'subject' => 'subjek',
        'body' => 'isi mesej',
        'to' => 'penerima',
        'slug' => 'slug',
        'meta_title' => 'tajuk meta',
        'meta_description' => 'penerangan meta',
        'sort_order' => 'susunan',
        'price' => 'harga',
        'duration' => 'tempoh',
        'days' => 'bilangan hari',
        'ip_address' => 'alamat IP',
        'image_url' => 'alamat imej',
        'link_url' => 'pautan',
        'starts_at' => 'masa mula',
        'expires_at' => 'masa tamat',
        'source' => 'alamat asal',
        'destination' => 'alamat destinasi',
        'icon' => 'ikon',
        'file' => 'fail',
        'label' => 'nama paparan',
        'code' => 'kod',
        'website' => 'laman web',
        'user_id' => 'nombor ahli',
        'video_id' => 'nombor video',
        'thumbnail_file' => 'gambar kecil',
        'thumbnail' => 'gambar kecil',
        'pages' => 'bilangan halaman',
        'start_page' => 'halaman mula',
        'pagesize' => 'video setiap halaman',
        'delay' => 'lengahan',
        'updated_hours' => 'jam sejak dikemas kini',
    ],

];
