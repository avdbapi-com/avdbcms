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

    'accepted' => 'يجب قبول :attribute.',
    'accepted_if' => 'يجب قبول :attribute عندما تكون قيمة :other هي :value.',
    'active_url' => 'حقل :attribute ليس عنوان ويب صالحًا.',
    'after' => 'يجب أن يكون حقل :attribute تاريخًا بعد :date.',
    'after_or_equal' => 'يجب أن يكون حقل :attribute هو :date أو تاريخًا بعده.',
    'alpha' => 'يجب ألا يحتوي حقل :attribute إلا على حروف.',
    'alpha_dash' => 'يجب ألا يحتوي حقل :attribute إلا على حروف وأرقام وشرطات وشرطات سفلية.',
    'alpha_num' => 'يجب ألا يحتوي حقل :attribute إلا على حروف وأرقام.',
    'array' => 'يجب أن يكون حقل :attribute قائمة.',
    'before' => 'يجب أن يكون حقل :attribute تاريخًا قبل :date.',
    'before_or_equal' => 'يجب أن يكون حقل :attribute هو :date أو تاريخًا قبله.',
    'between' => [
        'array' => 'يجب أن يكون عدد عناصر حقل :attribute بين :min و:max.',
        'file' => 'يجب أن يكون حجم ملف حقل :attribute بين :min و:max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute بين :min و:max.',
        'string' => 'يجب أن يكون عدد أحرف حقل :attribute بين :min و:max.',
    ],
    'boolean' => 'يجب أن تكون قيمة حقل :attribute نعم أو لا.',
    'confirmed' => 'حقل :attribute لا يطابق التأكيد.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => 'حقل :attribute ليس تاريخًا صالحًا.',
    'date_equals' => 'يجب أن يكون حقل :attribute هو التاريخ :date.',
    'date_format' => 'حقل :attribute لا يطابق التنسيق :format.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما تكون قيمة :other هي :value.',
    'different' => 'يجب أن يختلف حقل :attribute عن حقل :other.',
    'digits' => 'يجب أن يكون عدد أرقام حقل :attribute هو :digits.',
    'digits_between' => 'يجب أن يكون عدد أرقام حقل :attribute بين :min و:max.',
    'dimensions' => 'أبعاد صورة حقل :attribute غير مناسبة.',
    'distinct' => 'في حقل :attribute قيمة مكررة.',
    'doesnt_end_with' => 'يجب ألا ينتهي حقل :attribute بإحدى هذه القيم: :values.',
    'doesnt_start_with' => 'يجب ألا يبدأ حقل :attribute بإحدى هذه القيم: :values.',
    'email' => 'أدخل عنوان بريد إلكتروني صالحًا.',
    'ends_with' => 'يجب أن ينتهي حقل :attribute بإحدى هذه القيم: :values.',
    'enum' => 'القيمة المختارة لحقل :attribute غير صالحة.',
    'exists' => 'لم يتم العثور على القيمة المختارة لحقل :attribute.',
    'file' => 'يجب أن يكون حقل :attribute ملفًا.',
    'filled' => 'يجب ألا يكون حقل :attribute فارغًا.',
    'gt' => [
        'array' => 'يجب أن يكون عدد عناصر حقل :attribute أكثر من :value.',
        'file' => 'يجب أن يكون حجم ملف حقل :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute أكبر من :value.',
        'string' => 'يجب أن يكون عدد أحرف حقل :attribute أكثر من :value.',
    ],
    'gte' => [
        'array' => 'يجب ألا يقل عدد عناصر حقل :attribute عن :value.',
        'file' => 'يجب ألا يقل حجم ملف حقل :attribute عن :value كيلوبايت.',
        'numeric' => 'يجب ألا تقل قيمة حقل :attribute عن :value.',
        'string' => 'يجب ألا يقل عدد أحرف حقل :attribute عن :value.',
    ],
    'image' => 'يجب أن يكون حقل :attribute صورة.',
    'in' => 'القيمة المختارة لحقل :attribute غير صالحة.',
    'in_array' => 'يجب أن تكون قيمة حقل :attribute إحدى قيم حقل :other.',
    'integer' => 'يجب أن تكون قيمة حقل :attribute عددًا صحيحًا.',
    'ip' => 'يجب أن يكون حقل :attribute عنوان IP صالحًا.',
    'ipv4' => 'يجب أن يكون حقل :attribute عنوان IPv4 صالحًا.',
    'ipv6' => 'يجب أن يكون حقل :attribute عنوان IPv6 صالحًا.',
    'json' => 'يجب أن يكون حقل :attribute نص JSON صالحًا.',
    'lt' => [
        'array' => 'يجب أن يكون عدد عناصر حقل :attribute أقل من :value.',
        'file' => 'يجب أن يكون حجم ملف حقل :attribute أقل من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute أقل من :value.',
        'string' => 'يجب أن يكون عدد أحرف حقل :attribute أقل من :value.',
    ],
    'lte' => [
        'array' => 'يجب ألا يزيد عدد عناصر حقل :attribute على :value.',
        'file' => 'يجب ألا يزيد حجم ملف حقل :attribute على :value كيلوبايت.',
        'numeric' => 'يجب ألا تزيد قيمة حقل :attribute على :value.',
        'string' => 'يجب ألا يزيد عدد أحرف حقل :attribute على :value.',
    ],
    'mac_address' => 'يجب أن يكون حقل :attribute عنوان MAC صالحًا.',
    'max' => [
        'array' => 'يجب ألا يزيد عدد عناصر حقل :attribute على :max.',
        'file' => 'يجب ألا يزيد حجم ملف حقل :attribute على :max كيلوبايت.',
        'numeric' => 'يجب ألا تزيد قيمة حقل :attribute على :max.',
        'string' => 'يجب ألا يزيد عدد أحرف حقل :attribute على :max.',
    ],
    'max_digits' => 'يجب ألا يزيد عدد أرقام حقل :attribute على :max.',
    'mimes' => 'يجب أن يكون ملف حقل :attribute من أحد هذه الأنواع: :values.',
    'mimetypes' => 'يجب أن يكون ملف حقل :attribute من أحد هذه الأنواع: :values.',
    'min' => [
        'array' => 'يجب ألا يقل عدد عناصر حقل :attribute عن :min.',
        'file' => 'يجب ألا يقل حجم ملف حقل :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألا تقل قيمة حقل :attribute عن :min.',
        'string' => 'يجب ألا يقل عدد أحرف حقل :attribute عن :min.',
    ],
    'min_digits' => 'يجب ألا يقل عدد أرقام حقل :attribute عن :min.',
    'multiple_of' => 'يجب أن تكون قيمة حقل :attribute من مضاعفات :value.',
    'not_in' => 'القيمة المختارة لحقل :attribute غير صالحة.',
    'not_regex' => 'تنسيق حقل :attribute غير صالح.',
    'numeric' => 'يجب أن تكون قيمة حقل :attribute رقمًا.',
    'password' => [
        'letters' => 'يجب أن يحتوي حقل :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن يحتوي حقل :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن يحتوي حقل :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن يحتوي حقل :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت قيمة حقل :attribute في تسريب للبيانات. اختر قيمة أخرى.',
    ],
    'present' => 'يجب أن يكون حقل :attribute موجودًا.',
    'prohibited' => 'حقل :attribute غير مسموح به.',
    'prohibited_if' => 'حقل :attribute غير مسموح به عندما تكون قيمة :other هي :value.',
    'prohibited_unless' => 'حقل :attribute مسموح به فقط عندما تكون قيمة :other إحدى هذه القيم: :values.',
    'prohibits' => 'لا يمكن ملء حقل :attribute مع حقل :other.',
    'regex' => 'تنسيق حقل :attribute غير صالح.',
    'required' => 'حقل :attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي حقل :attribute على هذه القيم: :values.',
    'required_if' => 'حقل :attribute مطلوب عندما تكون قيمة :other هي :value.',
    'required_if_accepted' => 'حقل :attribute مطلوب عند قبول :other.',
    'required_unless' => 'حقل :attribute مطلوب إلا إذا كانت قيمة :other إحدى هذه القيم: :values.',
    'required_with' => 'حقل :attribute مطلوب عند ملء :values.',
    'required_with_all' => 'حقل :attribute مطلوب عند ملء كل هذه الحقول: :values.',
    'required_without' => 'حقل :attribute مطلوب عندما لا يكون :values مملوءًا.',
    'required_without_all' => 'حقل :attribute مطلوب عندما لا يكون أي من هذه الحقول مملوءًا: :values.',
    'same' => 'يجب أن يتطابق حقل :attribute مع حقل :other.',
    'size' => [
        'array' => 'يجب أن يكون عدد عناصر حقل :attribute هو :size.',
        'file' => 'يجب أن يكون حجم ملف حقل :attribute هو :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute هي :size.',
        'string' => 'يجب أن يكون عدد أحرف حقل :attribute هو :size.',
    ],
    'starts_with' => 'يجب أن يبدأ حقل :attribute بإحدى هذه القيم: :values.',
    'string' => 'يجب أن يكون حقل :attribute نصًا.',
    'timezone' => 'يجب أن يكون حقل :attribute منطقة زمنية صالحة.',
    'unique' => 'قيمة حقل :attribute مستخدمة بالفعل.',
    'uploaded' => 'تعذر رفع ملف حقل :attribute.',
    'url' => 'يجب أن يكون حقل :attribute عنوان ويب صالحًا.',
    'uuid' => 'يجب أن يكون حقل :attribute معرّف UUID صالحًا.',
    'phone' => 'يجب أن يكون حقل :attribute رقم هاتف صالحًا.',

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
            'exists' => 'هذا العنصر لم يعد متاحًا.',
        ],
        'parent_id' => [
            'exists' => 'التعليق الذي ترد عليه لم يعد متاحًا.',
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
        'username' => 'اسم المستخدم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'cgu' => 'شروط الخدمة',
        'title' => 'العنوان',
        'description' => 'الوصف',
        'category_id' => 'التصنيف',
        'language' => 'اللغة',
        'name' => 'الاسم',
        'message' => 'الرسالة',
        'avatar' => 'صورة الملف الشخصي',
        'banner' => 'البانر',
        'first_name' => 'الاسم الأول',
        'last_name' => 'اسم العائلة',
        'city' => 'المدينة',
        'country' => 'الدولة',
        'address' => 'العنوان البريدي',
        'phone' => 'الهاتف',
        'mobile' => 'الهاتف المحمول',
        'age' => 'العمر',
        'sex' => 'الجنس',
        'gender' => 'الجنس',
        'day' => 'اليوم',
        'month' => 'الشهر',
        'year' => 'السنة',
        'hour' => 'الساعة',
        'minute' => 'الدقيقة',
        'second' => 'الثانية',
        'content' => 'المحتوى',
        'excerpt' => 'المقتطف',
        'date' => 'التاريخ',
        'time' => 'الوقت',
        'available' => 'التوفر',
        'size' => 'الحجم',
        'comment' => 'التعليق',
        'reason' => 'السبب',
        'subject' => 'الموضوع',
        'body' => 'نص الرسالة',
        'to' => 'المستلم',
        'slug' => 'الاسم اللطيف',
        'meta_title' => 'عنوان الميتا',
        'meta_description' => 'وصف الميتا',
        'sort_order' => 'ترتيب العرض',
        'price' => 'السعر',
        'duration' => 'المدة',
        'days' => 'عدد الأيام',
        'ip_address' => 'عنوان IP',
        'image_url' => 'عنوان الصورة',
        'link_url' => 'الرابط',
        'starts_at' => 'البداية',
        'expires_at' => 'النهاية',
        'source' => 'عنوان المصدر',
        'destination' => 'عنوان الوجهة',
        'icon' => 'الأيقونة',
        'file' => 'الملف',
        'label' => 'الاسم الظاهر',
        'code' => 'الرمز',
        'website' => 'الموقع الإلكتروني',
        'user_id' => 'رقم العضو',
        'video_id' => 'رقم الفيديو',
        'thumbnail_file' => 'الصورة المصغرة',
        'thumbnail' => 'الصورة المصغرة',
        'pages' => 'عدد الصفحات',
        'start_page' => 'صفحة البداية',
        'pagesize' => 'عدد الفيديوهات في الصفحة',
        'delay' => 'التأخير',
        'updated_hours' => 'الساعات منذ التحديث',
    ],

];
