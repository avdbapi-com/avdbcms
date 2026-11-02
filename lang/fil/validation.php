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

    'accepted' => 'Kailangang tanggapin ang :attribute.',
    'accepted_if' => 'Kailangang tanggapin ang :attribute kapag ang :other ay :value.',
    'active_url' => 'Hindi tamang web address ang :attribute.',
    'after' => 'Kailangang petsa pagkatapos ng :date ang :attribute.',
    'after_or_equal' => 'Kailangang :date o mas huling petsa ang :attribute.',
    'alpha' => 'Mga titik lang ang puwede sa :attribute.',
    'alpha_dash' => 'Mga titik, numero, gitling at underscore lang ang puwede sa :attribute.',
    'alpha_num' => 'Mga titik at numero lang ang puwede sa :attribute.',
    'array' => 'Kailangang listahan ang :attribute.',
    'before' => 'Kailangang petsa bago ang :date ang :attribute.',
    'before_or_equal' => 'Kailangang :date o mas maagang petsa ang :attribute.',
    'between' => [
        'array' => 'Kailangang may :min hanggang :max item ang :attribute.',
        'file' => 'Kailangang :min hanggang :max kilobyte ang laki ng file na :attribute.',
        'numeric' => 'Kailangang nasa pagitan ng :min at :max ang :attribute.',
        'string' => 'Kailangang may :min hanggang :max karakter ang :attribute.',
    ],
    'boolean' => 'Kailangang oo o hindi ang :attribute.',
    'confirmed' => 'Hindi tugma ang :attribute sa kumpirmasyon nito.',
    'current_password' => 'Mali ang password.',
    'date' => 'Hindi tamang petsa ang :attribute.',
    'date_equals' => 'Kailangang :date ang petsa ng :attribute.',
    'date_format' => 'Hindi tugma ang :attribute sa format na :format.',
    'declined' => 'Kailangang tanggihan ang :attribute.',
    'declined_if' => 'Kailangang tanggihan ang :attribute kapag ang :other ay :value.',
    'different' => 'Kailangang magkaiba ang :attribute at ang :other.',
    'digits' => 'Kailangang may :digits digit ang :attribute.',
    'digits_between' => 'Kailangang may :min hanggang :max digit ang :attribute.',
    'dimensions' => 'Hindi tama ang sukat ng larawang :attribute.',
    'distinct' => 'May umuulit na value sa :attribute.',
    'doesnt_end_with' => 'Hindi puwedeng magtapos ang :attribute sa isa sa mga ito: :values.',
    'doesnt_start_with' => 'Hindi puwedeng magsimula ang :attribute sa isa sa mga ito: :values.',
    'email' => 'Maglagay ng tamang email address, gaya ng name@example.com.',
    'ends_with' => 'Kailangang magtapos ang :attribute sa isa sa mga ito: :values.',
    'enum' => 'Hindi tama ang napiling value para sa :attribute.',
    'exists' => 'Hindi nakita ang napiling value para sa :attribute.',
    'file' => 'Kailangang file ang :attribute.',
    'filled' => 'Hindi puwedeng walang laman ang :attribute.',
    'gt' => [
        'array' => 'Kailangang may higit sa :value item ang :attribute.',
        'file' => 'Kailangang higit sa :value kilobyte ang laki ng file na :attribute.',
        'numeric' => 'Kailangang higit sa :value ang :attribute.',
        'string' => 'Kailangang may higit sa :value karakter ang :attribute.',
    ],
    'gte' => [
        'array' => 'Kailangang may hindi bababa sa :value item ang :attribute.',
        'file' => 'Kailangang hindi bababa sa :value kilobyte ang laki ng file na :attribute.',
        'numeric' => 'Kailangang hindi bababa sa :value ang :attribute.',
        'string' => 'Kailangang may hindi bababa sa :value karakter ang :attribute.',
    ],
    'image' => 'Kailangang larawan ang :attribute.',
    'in' => 'Hindi tama ang napiling value para sa :attribute.',
    'in_array' => 'Kailangang isa sa mga value ng :other ang :attribute.',
    'integer' => 'Kailangang buong numero ang :attribute.',
    'ip' => 'Kailangang tamang IP address ang :attribute.',
    'ipv4' => 'Kailangang tamang IPv4 address ang :attribute.',
    'ipv6' => 'Kailangang tamang IPv6 address ang :attribute.',
    'json' => 'Kailangang tamang JSON text ang :attribute.',
    'lt' => [
        'array' => 'Kailangang may mas kaunti sa :value item ang :attribute.',
        'file' => 'Kailangang mas mababa sa :value kilobyte ang laki ng file na :attribute.',
        'numeric' => 'Kailangang mas mababa sa :value ang :attribute.',
        'string' => 'Kailangang may mas kaunti sa :value karakter ang :attribute.',
    ],
    'lte' => [
        'array' => 'Hindi puwedeng lumampas sa :value item ang :attribute.',
        'file' => 'Hindi puwedeng lumampas sa :value kilobyte ang laki ng file na :attribute.',
        'numeric' => 'Hindi puwedeng lumampas sa :value ang :attribute.',
        'string' => 'Hindi puwedeng lumampas sa :value karakter ang :attribute.',
    ],
    'mac_address' => 'Kailangang tamang MAC address ang :attribute.',
    'max' => [
        'array' => 'Hindi puwedeng lumampas sa :max item ang :attribute.',
        'file' => 'Hindi puwedeng lumampas sa :max kilobyte ang laki ng file na :attribute.',
        'numeric' => 'Hindi puwedeng lumampas sa :max ang :attribute.',
        'string' => 'Hindi puwedeng lumampas sa :max karakter ang :attribute.',
    ],
    'max_digits' => 'Hindi puwedeng lumampas sa :max digit ang :attribute.',
    'mimes' => 'Kailangang file na isa sa mga uring ito ang :attribute: :values.',
    'mimetypes' => 'Kailangang file na isa sa mga uring ito ang :attribute: :values.',
    'min' => [
        'array' => 'Kailangang may hindi bababa sa :min item ang :attribute.',
        'file' => 'Kailangang hindi bababa sa :min kilobyte ang laki ng file na :attribute.',
        'numeric' => 'Kailangang hindi bababa sa :min ang :attribute.',
        'string' => 'Kailangang may hindi bababa sa :min karakter ang :attribute.',
    ],
    'min_digits' => 'Kailangang may hindi bababa sa :min digit ang :attribute.',
    'multiple_of' => 'Kailangang multiple ng :value ang :attribute.',
    'not_in' => 'Hindi tama ang napiling value para sa :attribute.',
    'not_regex' => 'Hindi tama ang format ng :attribute.',
    'numeric' => 'Kailangang numero ang :attribute.',
    'password' => [
        'letters' => 'Kailangang may kahit isang titik ang :attribute.',
        'mixed' => 'Kailangang may kahit isang malaking titik at isang maliit na titik ang :attribute.',
        'numbers' => 'Kailangang may kahit isang numero ang :attribute.',
        'symbols' => 'Kailangang may kahit isang simbolo ang :attribute.',
        'uncompromised' => 'Lumabas na sa isang data leak ang :attribute na ito. Pumili ng iba.',
    ],
    'present' => 'Kailangang naroon ang :attribute.',
    'prohibited' => 'Hindi puwedeng punan ang :attribute.',
    'prohibited_if' => 'Hindi puwedeng punan ang :attribute kapag ang :other ay :value.',
    'prohibited_unless' => 'Puwede lang punan ang :attribute kapag ang :other ay isa sa mga ito: :values.',
    'prohibits' => 'Hindi puwedeng punan ang :attribute kasabay ng :other.',
    'regex' => 'Hindi tama ang format ng :attribute.',
    'required' => 'Kailangan ang :attribute.',
    'required_array_keys' => 'Kailangang may laman ang :attribute para sa: :values.',
    'required_if' => 'Kailangan ang :attribute kapag ang :other ay :value.',
    'required_if_accepted' => 'Kailangan ang :attribute kapag tinanggap ang :other.',
    'required_unless' => 'Kailangan ang :attribute maliban kung ang :other ay isa sa mga ito: :values.',
    'required_with' => 'Kailangan ang :attribute kapag may laman ang :values.',
    'required_with_all' => 'Kailangan ang :attribute kapag may laman ang lahat ng :values.',
    'required_without' => 'Kailangan ang :attribute kapag walang laman ang :values.',
    'required_without_all' => 'Kailangan ang :attribute kapag walang laman ang lahat ng ito: :values.',
    'same' => 'Kailangang magkatugma ang :attribute at ang :other.',
    'size' => [
        'array' => 'Kailangang may :size item ang :attribute.',
        'file' => 'Kailangang :size kilobyte ang laki ng file na :attribute.',
        'numeric' => 'Kailangang :size ang :attribute.',
        'string' => 'Kailangang may :size karakter ang :attribute.',
    ],
    'starts_with' => 'Kailangang magsimula ang :attribute sa isa sa mga ito: :values.',
    'string' => 'Kailangang text ang :attribute.',
    'timezone' => 'Kailangang tamang time zone ang :attribute.',
    'unique' => 'Ginagamit na ang :attribute na ito.',
    'uploaded' => 'Hindi na-upload ang :attribute.',
    'url' => 'Kailangang tamang web address ang :attribute.',
    'uuid' => 'Kailangang tamang UUID ang :attribute.',
    'phone' => 'Kailangang tamang numero ng telepono ang :attribute.',

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
            'exists' => 'Hindi na ito available.',
        ],
        'parent_id' => [
            'exists' => 'Hindi na available ang komentong sinasagot mo.',
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
        'username' => 'username',
        'email' => 'email',
        'password' => 'password',
        'password_confirmation' => 'kumpirmasyon ng password',
        'cgu' => 'mga tuntunin ng serbisyo',
        'title' => 'pamagat',
        'description' => 'deskripsyon',
        'category_id' => 'kategorya',
        'language' => 'wika',
        'name' => 'pangalan',
        'message' => 'mensahe',
        'avatar' => 'larawan sa profile',
        'banner' => 'banner',
        'first_name' => 'unang pangalan',
        'last_name' => 'apelyido',
        'city' => 'lungsod',
        'country' => 'bansa',
        'address' => 'address',
        'phone' => 'telepono',
        'mobile' => 'mobile number',
        'age' => 'edad',
        'sex' => 'kasarian',
        'gender' => 'kasarian',
        'day' => 'araw',
        'month' => 'buwan',
        'year' => 'taon',
        'hour' => 'oras',
        'minute' => 'minuto',
        'second' => 'segundo',
        'content' => 'nilalaman',
        'excerpt' => 'sipi',
        'date' => 'petsa',
        'time' => 'oras',
        'available' => 'availability',
        'size' => 'laki',
        'comment' => 'komento',
        'reason' => 'dahilan',
        'subject' => 'paksa',
        'body' => 'nilalaman ng mensahe',
        'to' => 'tatanggap',
        'slug' => 'slug',
        'meta_title' => 'meta title',
        'meta_description' => 'meta description',
        'sort_order' => 'pagkakasunod-sunod',
        'price' => 'presyo',
        'duration' => 'tagal',
        'days' => 'bilang ng araw',
        'ip_address' => 'IP address',
        'image_url' => 'address ng larawan',
        'link_url' => 'link',
        'starts_at' => 'simula',
        'expires_at' => 'pagtatapos',
        'source' => 'pinagmulang address',
        'destination' => 'patutunguhang address',
        'icon' => 'icon',
        'file' => 'file',
        'label' => 'pangalang ipinapakita',
        'code' => 'code',
        'website' => 'website',
        'user_id' => 'numero ng miyembro',
        'video_id' => 'numero ng video',
        'thumbnail_file' => 'thumbnail',
        'thumbnail' => 'thumbnail',
        'pages' => 'bilang ng pahina',
        'start_page' => 'panimulang pahina',
        'pagesize' => 'video bawat pahina',
        'delay' => 'antala',
        'updated_hours' => 'oras mula nang ma-update',
    ],

];
