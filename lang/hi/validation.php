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

    'accepted' => ':attribute को स्वीकार करना ज़रूरी है।',
    'accepted_if' => 'जब :other का मान :value हो, तब :attribute को स्वीकार करना ज़रूरी है।',
    'active_url' => ':attribute एक मान्य वेब पता नहीं है।',
    'after' => ':attribute में :date के बाद की तारीख होनी चाहिए।',
    'after_or_equal' => ':attribute में :date या उसके बाद की तारीख होनी चाहिए।',
    'alpha' => ':attribute में केवल अक्षर हो सकते हैं।',
    'alpha_dash' => ':attribute में केवल अक्षर, अंक, डैश और अंडरस्कोर हो सकते हैं।',
    'alpha_num' => ':attribute में केवल अक्षर और अंक हो सकते हैं।',
    'array' => ':attribute एक सूची होनी चाहिए।',
    'before' => ':attribute में :date से पहले की तारीख होनी चाहिए।',
    'before_or_equal' => ':attribute में :date या उससे पहले की तारीख होनी चाहिए।',
    'between' => [
        'array' => ':attribute में :min से :max तक आइटम होने चाहिए।',
        'file' => ':attribute फ़ाइल का आकार :min से :max किलोबाइट तक होना चाहिए।',
        'numeric' => ':attribute का मान :min से :max तक होना चाहिए।',
        'string' => ':attribute में :min से :max तक अक्षर होने चाहिए।',
    ],
    'boolean' => ':attribute का मान हाँ या नहीं होना चाहिए।',
    'confirmed' => ':attribute और उसकी पुष्टि एक जैसे नहीं हैं।',
    'current_password' => 'पासवर्ड गलत है।',
    'date' => ':attribute एक मान्य तारीख नहीं है।',
    'date_equals' => ':attribute में तारीख :date होनी चाहिए।',
    'date_format' => ':attribute का प्रारूप :format होना चाहिए।',
    'declined' => ':attribute को अस्वीकार करना ज़रूरी है।',
    'declined_if' => 'जब :other का मान :value हो, तब :attribute को अस्वीकार करना ज़रूरी है।',
    'different' => ':attribute और :other अलग-अलग होने चाहिए।',
    'digits' => ':attribute में :digits अंक होने चाहिए।',
    'digits_between' => ':attribute में :min से :max तक अंक होने चाहिए।',
    'dimensions' => ':attribute छवि का आकार सही नहीं है।',
    'distinct' => ':attribute में एक मान दोहराया गया है।',
    'doesnt_end_with' => ':attribute का अंत इनमें से किसी मान से नहीं होना चाहिए: :values।',
    'doesnt_start_with' => ':attribute की शुरुआत इनमें से किसी मान से नहीं होनी चाहिए: :values।',
    'email' => 'सही ईमेल पता लिखें, जैसे name@example.com।',
    'ends_with' => ':attribute का अंत इनमें से किसी मान से होना चाहिए: :values।',
    'enum' => ':attribute के लिए चुना गया मान मान्य नहीं है।',
    'exists' => ':attribute के लिए चुना गया मान नहीं मिला।',
    'file' => ':attribute एक फ़ाइल होनी चाहिए।',
    'filled' => ':attribute को खाली नहीं छोड़ा जा सकता।',
    'gt' => [
        'array' => ':attribute में :value से ज़्यादा आइटम होने चाहिए।',
        'file' => ':attribute फ़ाइल का आकार :value किलोबाइट से ज़्यादा होना चाहिए।',
        'numeric' => ':attribute का मान :value से ज़्यादा होना चाहिए।',
        'string' => ':attribute में :value से ज़्यादा अक्षर होने चाहिए।',
    ],
    'gte' => [
        'array' => ':attribute में कम से कम :value आइटम होने चाहिए।',
        'file' => ':attribute फ़ाइल का आकार कम से कम :value किलोबाइट होना चाहिए।',
        'numeric' => ':attribute का मान कम से कम :value होना चाहिए।',
        'string' => ':attribute में कम से कम :value अक्षर होने चाहिए।',
    ],
    'image' => ':attribute एक छवि होनी चाहिए।',
    'in' => ':attribute के लिए चुना गया मान मान्य नहीं है।',
    'in_array' => ':attribute का मान :other के मानों में से एक होना चाहिए।',
    'integer' => ':attribute का मान एक पूर्ण संख्या होना चाहिए।',
    'ip' => ':attribute एक मान्य IP पता होना चाहिए।',
    'ipv4' => ':attribute एक मान्य IPv4 पता होना चाहिए।',
    'ipv6' => ':attribute एक मान्य IPv6 पता होना चाहिए।',
    'json' => ':attribute मान्य JSON टेक्स्ट होना चाहिए।',
    'lt' => [
        'array' => ':attribute में :value से कम आइटम होने चाहिए।',
        'file' => ':attribute फ़ाइल का आकार :value किलोबाइट से कम होना चाहिए।',
        'numeric' => ':attribute का मान :value से कम होना चाहिए।',
        'string' => ':attribute में :value से कम अक्षर होने चाहिए।',
    ],
    'lte' => [
        'array' => ':attribute में :value से ज़्यादा आइटम नहीं होने चाहिए।',
        'file' => ':attribute फ़ाइल का आकार :value किलोबाइट से ज़्यादा नहीं होना चाहिए।',
        'numeric' => ':attribute का मान :value से ज़्यादा नहीं होना चाहिए।',
        'string' => ':attribute में :value से ज़्यादा अक्षर नहीं होने चाहिए।',
    ],
    'mac_address' => ':attribute एक मान्य MAC पता होना चाहिए।',
    'max' => [
        'array' => ':attribute में :max से ज़्यादा आइटम नहीं होने चाहिए।',
        'file' => ':attribute फ़ाइल का आकार :max किलोबाइट से ज़्यादा नहीं होना चाहिए।',
        'numeric' => ':attribute का मान :max से ज़्यादा नहीं होना चाहिए।',
        'string' => ':attribute में :max से ज़्यादा अक्षर नहीं होने चाहिए।',
    ],
    'max_digits' => ':attribute में :max से ज़्यादा अंक नहीं होने चाहिए।',
    'mimes' => ':attribute इनमें से किसी प्रकार की फ़ाइल होनी चाहिए: :values।',
    'mimetypes' => ':attribute इनमें से किसी प्रकार की फ़ाइल होनी चाहिए: :values।',
    'min' => [
        'array' => ':attribute में कम से कम :min आइटम होने चाहिए।',
        'file' => ':attribute फ़ाइल का आकार कम से कम :min किलोबाइट होना चाहिए।',
        'numeric' => ':attribute का मान कम से कम :min होना चाहिए।',
        'string' => ':attribute में कम से कम :min अक्षर होने चाहिए।',
    ],
    'min_digits' => ':attribute में कम से कम :min अंक होने चाहिए।',
    'multiple_of' => ':attribute का मान :value का गुणज होना चाहिए।',
    'not_in' => ':attribute के लिए चुना गया मान मान्य नहीं है।',
    'not_regex' => ':attribute का प्रारूप मान्य नहीं है।',
    'numeric' => ':attribute का मान एक संख्या होना चाहिए।',
    'password' => [
        'letters' => ':attribute में कम से कम एक अक्षर होना चाहिए।',
        'mixed' => ':attribute में कम से कम एक बड़ा और एक छोटा अक्षर होना चाहिए।',
        'numbers' => ':attribute में कम से कम एक अंक होना चाहिए।',
        'symbols' => ':attribute में कम से कम एक विशेष चिह्न होना चाहिए।',
        'uncompromised' => 'यह :attribute किसी डेटा लीक में मिला है। कोई दूसरा चुनें।',
    ],
    'present' => ':attribute मौजूद होना चाहिए।',
    'prohibited' => ':attribute भरने की अनुमति नहीं है।',
    'prohibited_if' => 'जब :other का मान :value हो, तब :attribute भरने की अनुमति नहीं है।',
    'prohibited_unless' => ':attribute तभी भरा जा सकता है जब :other का मान इनमें से एक हो: :values।',
    'prohibits' => ':attribute के साथ :other नहीं भरा जा सकता।',
    'regex' => ':attribute का प्रारूप मान्य नहीं है।',
    'required' => ':attribute आवश्यक है।',
    'required_array_keys' => ':attribute में ये मान होने चाहिए: :values।',
    'required_if' => 'जब :other का मान :value हो, तब :attribute आवश्यक है।',
    'required_if_accepted' => 'जब :other स्वीकार किया गया हो, तब :attribute आवश्यक है।',
    'required_unless' => ':attribute आवश्यक है, जब तक :other का मान इनमें से एक न हो: :values।',
    'required_with' => 'जब :values भरा हो, तब :attribute आवश्यक है।',
    'required_with_all' => 'जब :values भरे हों, तब :attribute आवश्यक है।',
    'required_without' => 'जब :values न भरा हो, तब :attribute आवश्यक है।',
    'required_without_all' => ':attribute आवश्यक है, जब इनमें से कोई भी फ़ील्ड न भरा हो: :values।',
    'same' => ':attribute और :other एक जैसे होने चाहिए।',
    'size' => [
        'array' => ':attribute में :size आइटम होने चाहिए।',
        'file' => ':attribute फ़ाइल का आकार :size किलोबाइट होना चाहिए।',
        'numeric' => ':attribute का मान :size होना चाहिए।',
        'string' => ':attribute में :size अक्षर होने चाहिए।',
    ],
    'starts_with' => ':attribute की शुरुआत इनमें से किसी मान से होनी चाहिए: :values।',
    'string' => ':attribute टेक्स्ट होना चाहिए।',
    'timezone' => ':attribute एक मान्य समय क्षेत्र होना चाहिए।',
    'unique' => 'यह :attribute पहले से इस्तेमाल हो रहा है।',
    'uploaded' => ':attribute अपलोड नहीं हो सका।',
    'url' => ':attribute एक मान्य वेब पता होना चाहिए।',
    'uuid' => ':attribute एक मान्य UUID होना चाहिए।',
    'phone' => ':attribute एक मान्य फ़ोन नंबर होना चाहिए।',

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
            'exists' => 'यह अब उपलब्ध नहीं है।',
        ],
        'parent_id' => [
            'exists' => 'जिस टिप्पणी का आप जवाब दे रहे हैं, वह अब उपलब्ध नहीं है।',
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
        'username' => 'उपयोगकर्ता नाम',
        'email' => 'ईमेल',
        'password' => 'पासवर्ड',
        'password_confirmation' => 'पासवर्ड की पुष्टि',
        'cgu' => 'सेवा की शर्तें',
        'title' => 'शीर्षक',
        'description' => 'विवरण',
        'category_id' => 'श्रेणी',
        'language' => 'भाषा',
        'name' => 'नाम',
        'message' => 'संदेश',
        'avatar' => 'प्रोफ़ाइल चित्र',
        'banner' => 'बैनर',
        'first_name' => 'पहला नाम',
        'last_name' => 'उपनाम',
        'city' => 'शहर',
        'country' => 'देश',
        'address' => 'पता',
        'phone' => 'फ़ोन',
        'mobile' => 'मोबाइल नंबर',
        'age' => 'उम्र',
        'sex' => 'लिंग',
        'gender' => 'लिंग',
        'day' => 'दिन',
        'month' => 'महीना',
        'year' => 'वर्ष',
        'hour' => 'घंटा',
        'minute' => 'मिनट',
        'second' => 'सेकंड',
        'content' => 'सामग्री',
        'excerpt' => 'अंश',
        'date' => 'तिथि',
        'time' => 'समय',
        'available' => 'उपलब्धता',
        'size' => 'आकार',
        'comment' => 'टिप्पणी',
        'reason' => 'कारण',
        'subject' => 'विषय',
        'body' => 'संदेश का मुख्य भाग',
        'to' => 'प्राप्तकर्ता',
        'slug' => 'स्लग',
        'meta_title' => 'मेटा शीर्षक',
        'meta_description' => 'मेटा विवरण',
        'sort_order' => 'क्रम',
        'price' => 'मूल्य',
        'duration' => 'अवधि',
        'days' => 'दिनों की संख्या',
        'ip_address' => 'IP पता',
        'image_url' => 'चित्र का पता',
        'link_url' => 'लिंक',
        'starts_at' => 'शुरुआत',
        'expires_at' => 'समाप्ति',
        'source' => 'स्रोत पता',
        'destination' => 'गंतव्य पता',
        'icon' => 'आइकन',
        'file' => 'फ़ाइल',
        'label' => 'दिखने वाला नाम',
        'code' => 'कोड',
        'website' => 'वेबसाइट',
        'user_id' => 'सदस्य संख्या',
        'video_id' => 'वीडियो संख्या',
        'thumbnail_file' => 'थंबनेल',
        'thumbnail' => 'थंबनेल',
        'pages' => 'पेजों की संख्या',
        'start_page' => 'शुरुआती पेज',
        'pagesize' => 'प्रति पेज वीडियो',
        'delay' => 'विलंब',
        'updated_hours' => 'अपडेट के बाद के घंटे',
    ],

];
