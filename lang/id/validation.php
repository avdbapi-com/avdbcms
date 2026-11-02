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

    'accepted' => ':Attribute harus disetujui.',
    'accepted_if' => ':Attribute harus disetujui jika :other bernilai :value.',
    'active_url' => ':Attribute bukan alamat web yang valid.',
    'after' => ':Attribute harus berupa tanggal setelah :date.',
    'after_or_equal' => ':Attribute harus berupa tanggal :date atau sesudahnya.',
    'alpha' => ':Attribute hanya boleh berisi huruf.',
    'alpha_dash' => ':Attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
    'alpha_num' => ':Attribute hanya boleh berisi huruf dan angka.',
    'array' => ':Attribute harus berupa daftar.',
    'before' => ':Attribute harus berupa tanggal sebelum :date.',
    'before_or_equal' => ':Attribute harus berupa tanggal :date atau sebelumnya.',
    'between' => [
        'array' => ':Attribute harus berisi :min sampai :max item.',
        'file' => 'Ukuran file :attribute harus antara :min dan :max kilobyte.',
        'numeric' => ':Attribute harus bernilai antara :min dan :max.',
        'string' => ':Attribute harus berisi :min sampai :max karakter.',
    ],
    'boolean' => ':Attribute harus bernilai ya atau tidak.',
    'confirmed' => ':Attribute tidak sama dengan konfirmasinya.',
    'current_password' => 'Kata sandi salah.',
    'date' => ':Attribute bukan tanggal yang valid.',
    'date_equals' => ':Attribute harus berupa tanggal :date.',
    'date_format' => ':Attribute tidak sesuai dengan format :format.',
    'declined' => ':Attribute harus ditolak.',
    'declined_if' => ':Attribute harus ditolak jika :other bernilai :value.',
    'different' => ':Attribute dan :other harus berbeda.',
    'digits' => ':Attribute harus terdiri dari :digits angka.',
    'digits_between' => ':Attribute harus terdiri dari :min sampai :max angka.',
    'dimensions' => 'Ukuran gambar :attribute tidak sesuai.',
    'distinct' => ':Attribute berisi nilai ganda.',
    'doesnt_end_with' => ':Attribute tidak boleh diakhiri salah satu nilai ini: :values.',
    'doesnt_start_with' => ':Attribute tidak boleh diawali salah satu nilai ini: :values.',
    'email' => 'Masukkan alamat email yang valid, misalnya name@example.com.',
    'ends_with' => ':Attribute harus diakhiri salah satu nilai ini: :values.',
    'enum' => 'Nilai yang dipilih untuk :attribute tidak valid.',
    'exists' => 'Nilai yang dipilih untuk :attribute tidak ditemukan.',
    'file' => ':Attribute harus berupa file.',
    'filled' => ':Attribute tidak boleh kosong.',
    'gt' => [
        'array' => ':Attribute harus berisi lebih dari :value item.',
        'file' => 'Ukuran file :attribute harus lebih dari :value kilobyte.',
        'numeric' => ':Attribute harus lebih besar dari :value.',
        'string' => ':Attribute harus berisi lebih dari :value karakter.',
    ],
    'gte' => [
        'array' => ':Attribute harus berisi minimal :value item.',
        'file' => 'Ukuran file :attribute harus minimal :value kilobyte.',
        'numeric' => ':Attribute harus minimal :value.',
        'string' => ':Attribute harus berisi minimal :value karakter.',
    ],
    'image' => ':Attribute harus berupa gambar.',
    'in' => 'Nilai yang dipilih untuk :attribute tidak valid.',
    'in_array' => ':Attribute harus salah satu nilai dari :other.',
    'integer' => ':Attribute harus berupa bilangan bulat.',
    'ip' => ':Attribute harus berupa alamat IP yang valid.',
    'ipv4' => ':Attribute harus berupa alamat IPv4 yang valid.',
    'ipv6' => ':Attribute harus berupa alamat IPv6 yang valid.',
    'json' => ':Attribute harus berupa teks JSON yang valid.',
    'lt' => [
        'array' => ':Attribute harus berisi kurang dari :value item.',
        'file' => 'Ukuran file :attribute harus kurang dari :value kilobyte.',
        'numeric' => ':Attribute harus lebih kecil dari :value.',
        'string' => ':Attribute harus berisi kurang dari :value karakter.',
    ],
    'lte' => [
        'array' => ':Attribute tidak boleh berisi lebih dari :value item.',
        'file' => 'Ukuran file :attribute tidak boleh lebih dari :value kilobyte.',
        'numeric' => ':Attribute tidak boleh lebih dari :value.',
        'string' => ':Attribute tidak boleh berisi lebih dari :value karakter.',
    ],
    'mac_address' => ':Attribute harus berupa alamat MAC yang valid.',
    'max' => [
        'array' => ':Attribute tidak boleh berisi lebih dari :max item.',
        'file' => 'Ukuran file :attribute tidak boleh lebih dari :max kilobyte.',
        'numeric' => ':Attribute tidak boleh lebih dari :max.',
        'string' => ':Attribute tidak boleh lebih dari :max karakter.',
    ],
    'max_digits' => ':Attribute tidak boleh lebih dari :max angka.',
    'mimes' => ':Attribute harus berupa file dengan jenis: :values.',
    'mimetypes' => ':Attribute harus berupa file dengan jenis: :values.',
    'min' => [
        'array' => ':Attribute harus berisi minimal :min item.',
        'file' => 'Ukuran file :attribute harus minimal :min kilobyte.',
        'numeric' => ':Attribute harus minimal :min.',
        'string' => ':Attribute harus berisi minimal :min karakter.',
    ],
    'min_digits' => ':Attribute harus berisi minimal :min angka.',
    'multiple_of' => ':Attribute harus kelipatan dari :value.',
    'not_in' => 'Nilai yang dipilih untuk :attribute tidak valid.',
    'not_regex' => 'Format :attribute tidak valid.',
    'numeric' => ':Attribute harus berupa angka.',
    'password' => [
        'letters' => ':Attribute harus berisi minimal satu huruf.',
        'mixed' => ':Attribute harus berisi minimal satu huruf besar dan satu huruf kecil.',
        'numbers' => ':Attribute harus berisi minimal satu angka.',
        'symbols' => ':Attribute harus berisi minimal satu simbol.',
        'uncompromised' => ':Attribute ini pernah muncul dalam kebocoran data. Pilih yang lain.',
    ],
    'present' => ':Attribute harus ada.',
    'prohibited' => ':Attribute tidak boleh diisi.',
    'prohibited_if' => ':Attribute tidak boleh diisi jika :other bernilai :value.',
    'prohibited_unless' => ':Attribute hanya boleh diisi jika :other bernilai salah satu dari: :values.',
    'prohibits' => ':Attribute tidak boleh diisi bersama :other.',
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':Attribute wajib diisi.',
    'required_array_keys' => ':Attribute harus berisi nilai untuk: :values.',
    'required_if' => ':Attribute wajib diisi jika :other bernilai :value.',
    'required_if_accepted' => ':Attribute wajib diisi jika :other disetujui.',
    'required_unless' => ':Attribute wajib diisi kecuali :other bernilai salah satu dari: :values.',
    'required_with' => ':Attribute wajib diisi jika :values diisi.',
    'required_with_all' => ':Attribute wajib diisi jika :values semuanya diisi.',
    'required_without' => ':Attribute wajib diisi jika :values tidak diisi.',
    'required_without_all' => ':Attribute wajib diisi jika tidak ada satu pun yang diisi dari: :values.',
    'same' => ':Attribute dan :other harus sama.',
    'size' => [
        'array' => ':Attribute harus berisi :size item.',
        'file' => 'Ukuran file :attribute harus :size kilobyte.',
        'numeric' => ':Attribute harus bernilai :size.',
        'string' => ':Attribute harus berisi :size karakter.',
    ],
    'starts_with' => ':Attribute harus diawali salah satu nilai ini: :values.',
    'string' => ':Attribute harus berupa teks.',
    'timezone' => ':Attribute harus berupa zona waktu yang valid.',
    'unique' => ':Attribute ini sudah digunakan.',
    'uploaded' => ':Attribute gagal diunggah.',
    'url' => ':Attribute harus berupa alamat web yang valid.',
    'uuid' => ':Attribute harus berupa UUID yang valid.',
    'phone' => ':Attribute harus berupa nomor telepon yang valid.',

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
            'exists' => 'Konten ini sudah tidak tersedia.',
        ],
        'parent_id' => [
            'exists' => 'Komentar yang Anda balas sudah tidak tersedia.',
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
        'email' => 'e-mail',
        'password' => 'kata sandi',
        'password_confirmation' => 'konfirmasi kata sandi',
        'cgu' => 'persyaratan layanan',
        'title' => 'judul',
        'description' => 'deskripsi',
        'category_id' => 'kategori',
        'language' => 'bahasa',
        'name' => 'nama',
        'message' => 'pesan',
        'avatar' => 'gambar profil',
        'banner' => 'spanduk',
        'first_name' => 'nama depan',
        'last_name' => 'nama belakang',
        'city' => 'kota',
        'country' => 'negara',
        'address' => 'alamat',
        'phone' => 'telepon',
        'mobile' => 'nomor ponsel',
        'age' => 'usia',
        'sex' => 'jenis kelamin',
        'gender' => 'gender',
        'day' => 'hari',
        'month' => 'bulan',
        'year' => 'tahun',
        'hour' => 'jam',
        'minute' => 'menit',
        'second' => 'detik',
        'content' => 'konten',
        'excerpt' => 'kutipan',
        'date' => 'tanggal',
        'time' => 'waktu',
        'available' => 'ketersediaan',
        'size' => 'ukuran',
        'comment' => 'komentar',
        'reason' => 'alasan',
        'subject' => 'subjek',
        'body' => 'isi pesan',
        'to' => 'penerima',
        'slug' => 'slug',
        'meta_title' => 'judul meta',
        'meta_description' => 'deskripsi meta',
        'sort_order' => 'urutan',
        'price' => 'harga',
        'duration' => 'durasi',
        'days' => 'jumlah hari',
        'ip_address' => 'alamat IP',
        'image_url' => 'alamat gambar',
        'link_url' => 'tautan',
        'starts_at' => 'waktu mulai',
        'expires_at' => 'waktu berakhir',
        'source' => 'alamat asal',
        'destination' => 'alamat tujuan',
        'icon' => 'ikon',
        'file' => 'berkas',
        'label' => 'nama tampilan',
        'code' => 'kode',
        'website' => 'situs web',
        'user_id' => 'nomor anggota',
        'video_id' => 'nomor video',
        'thumbnail_file' => 'gambar mini',
        'thumbnail' => 'gambar mini',
        'pages' => 'jumlah halaman',
        'start_page' => 'halaman awal',
        'pagesize' => 'video per halaman',
        'delay' => 'jeda',
        'updated_hours' => 'jam sejak pembaruan',
    ],

];
