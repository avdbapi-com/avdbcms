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

    'accepted' => 'ต้องยอมรับ :attribute',
    'accepted_if' => 'ต้องยอมรับ :attribute เมื่อ :other เป็น :value',
    'active_url' => ':attribute ไม่ใช่ที่อยู่เว็บที่ถูกต้อง',
    'after' => ':attribute ต้องเป็นวันที่หลัง :date',
    'after_or_equal' => ':attribute ต้องเป็นวันที่ :date หรือหลังจากนั้น',
    'alpha' => ':attribute ต้องมีเฉพาะตัวอักษร',
    'alpha_dash' => ':attribute ต้องมีเฉพาะตัวอักษร ตัวเลข ขีด และขีดล่าง',
    'alpha_num' => ':attribute ต้องมีเฉพาะตัวอักษรและตัวเลข',
    'array' => ':attribute ต้องเป็นรายการ',
    'before' => ':attribute ต้องเป็นวันที่ก่อน :date',
    'before_or_equal' => ':attribute ต้องเป็นวันที่ :date หรือก่อนหน้านั้น',
    'between' => [
        'array' => ':attribute ต้องมี :min ถึง :max รายการ',
        'file' => 'ไฟล์ :attribute ต้องมีขนาด :min ถึง :max กิโลไบต์',
        'numeric' => ':attribute ต้องอยู่ระหว่าง :min ถึง :max',
        'string' => ':attribute ต้องมี :min ถึง :max ตัวอักษร',
    ],
    'boolean' => ':attribute ต้องเป็นใช่หรือไม่ใช่',
    'confirmed' => ':attribute ไม่ตรงกับการยืนยัน',
    'current_password' => 'รหัสผ่านไม่ถูกต้อง',
    'date' => ':attribute ไม่ใช่วันที่ที่ถูกต้อง',
    'date_equals' => ':attribute ต้องเป็นวันที่ :date',
    'date_format' => ':attribute ไม่ตรงกับรูปแบบ :format',
    'declined' => 'ต้องปฏิเสธ :attribute',
    'declined_if' => 'ต้องปฏิเสธ :attribute เมื่อ :other เป็น :value',
    'different' => ':attribute และ :other ต้องไม่เหมือนกัน',
    'digits' => ':attribute ต้องเป็นตัวเลข :digits หลัก',
    'digits_between' => ':attribute ต้องเป็นตัวเลข :min ถึง :max หลัก',
    'dimensions' => 'ขนาดรูปภาพของ :attribute ไม่เหมาะสม',
    'distinct' => ':attribute มีค่าซ้ำกัน',
    'doesnt_end_with' => ':attribute ต้องไม่ลงท้ายด้วยค่าใดค่าหนึ่งต่อไปนี้: :values',
    'doesnt_start_with' => ':attribute ต้องไม่ขึ้นต้นด้วยค่าใดค่าหนึ่งต่อไปนี้: :values',
    'email' => 'กรอกที่อยู่อีเมลที่ถูกต้อง เช่น name@example.com',
    'ends_with' => ':attribute ต้องลงท้ายด้วยค่าใดค่าหนึ่งต่อไปนี้: :values',
    'enum' => 'ค่าที่เลือกสำหรับ :attribute ไม่ถูกต้อง',
    'exists' => 'ไม่พบค่าที่เลือกสำหรับ :attribute',
    'file' => ':attribute ต้องเป็นไฟล์',
    'filled' => ':attribute ต้องไม่ว่างเปล่า',
    'gt' => [
        'array' => ':attribute ต้องมีมากกว่า :value รายการ',
        'file' => 'ไฟล์ :attribute ต้องมีขนาดมากกว่า :value กิโลไบต์',
        'numeric' => ':attribute ต้องมากกว่า :value',
        'string' => ':attribute ต้องมีมากกว่า :value ตัวอักษร',
    ],
    'gte' => [
        'array' => ':attribute ต้องมีอย่างน้อย :value รายการ',
        'file' => 'ไฟล์ :attribute ต้องมีขนาดอย่างน้อย :value กิโลไบต์',
        'numeric' => ':attribute ต้องมากกว่าหรือเท่ากับ :value',
        'string' => ':attribute ต้องมีอย่างน้อย :value ตัวอักษร',
    ],
    'image' => ':attribute ต้องเป็นรูปภาพ',
    'in' => 'ค่าที่เลือกสำหรับ :attribute ไม่ถูกต้อง',
    'in_array' => ':attribute ต้องเป็นค่าหนึ่งของ :other',
    'integer' => ':attribute ต้องเป็นจำนวนเต็ม',
    'ip' => ':attribute ต้องเป็นที่อยู่ IP ที่ถูกต้อง',
    'ipv4' => ':attribute ต้องเป็นที่อยู่ IPv4 ที่ถูกต้อง',
    'ipv6' => ':attribute ต้องเป็นที่อยู่ IPv6 ที่ถูกต้อง',
    'json' => ':attribute ต้องเป็นข้อความ JSON ที่ถูกต้อง',
    'lt' => [
        'array' => ':attribute ต้องมีน้อยกว่า :value รายการ',
        'file' => 'ไฟล์ :attribute ต้องมีขนาดน้อยกว่า :value กิโลไบต์',
        'numeric' => ':attribute ต้องน้อยกว่า :value',
        'string' => ':attribute ต้องมีน้อยกว่า :value ตัวอักษร',
    ],
    'lte' => [
        'array' => ':attribute ต้องมีไม่เกิน :value รายการ',
        'file' => 'ไฟล์ :attribute ต้องมีขนาดไม่เกิน :value กิโลไบต์',
        'numeric' => ':attribute ต้องไม่เกิน :value',
        'string' => ':attribute ต้องมีไม่เกิน :value ตัวอักษร',
    ],
    'mac_address' => ':attribute ต้องเป็นที่อยู่ MAC ที่ถูกต้อง',
    'max' => [
        'array' => ':attribute ต้องมีไม่เกิน :max รายการ',
        'file' => 'ไฟล์ :attribute ต้องมีขนาดไม่เกิน :max กิโลไบต์',
        'numeric' => ':attribute ต้องไม่เกิน :max',
        'string' => ':attribute ต้องมีไม่เกิน :max ตัวอักษร',
    ],
    'max_digits' => ':attribute ต้องมีไม่เกิน :max หลัก',
    'mimes' => ':attribute ต้องเป็นไฟล์ประเภทต่อไปนี้: :values',
    'mimetypes' => ':attribute ต้องเป็นไฟล์ประเภทต่อไปนี้: :values',
    'min' => [
        'array' => ':attribute ต้องมีอย่างน้อย :min รายการ',
        'file' => 'ไฟล์ :attribute ต้องมีขนาดอย่างน้อย :min กิโลไบต์',
        'numeric' => ':attribute ต้องมีค่าอย่างน้อย :min',
        'string' => ':attribute ต้องมีอย่างน้อย :min ตัวอักษร',
    ],
    'min_digits' => ':attribute ต้องมีอย่างน้อย :min หลัก',
    'multiple_of' => ':attribute ต้องเป็นผลคูณของ :value',
    'not_in' => 'ค่าที่เลือกสำหรับ :attribute ไม่ถูกต้อง',
    'not_regex' => 'รูปแบบของ :attribute ไม่ถูกต้อง',
    'numeric' => ':attribute ต้องเป็นตัวเลข',
    'password' => [
        'letters' => ':attribute ต้องมีตัวอักษรอย่างน้อยหนึ่งตัว',
        'mixed' => ':attribute ต้องมีตัวพิมพ์ใหญ่และตัวพิมพ์เล็กอย่างน้อยอย่างละหนึ่งตัว',
        'numbers' => ':attribute ต้องมีตัวเลขอย่างน้อยหนึ่งตัว',
        'symbols' => ':attribute ต้องมีสัญลักษณ์อย่างน้อยหนึ่งตัว',
        'uncompromised' => ':attribute นี้เคยปรากฏในข้อมูลที่รั่วไหล โปรดเลือกอันอื่น',
    ],
    'present' => 'ต้องมี :attribute',
    'prohibited' => 'ไม่อนุญาตให้กรอก :attribute',
    'prohibited_if' => 'ไม่อนุญาตให้กรอก :attribute เมื่อ :other เป็น :value',
    'prohibited_unless' => 'กรอก :attribute ได้เฉพาะเมื่อ :other เป็นค่าใดค่าหนึ่งต่อไปนี้: :values',
    'prohibits' => 'กรอก :attribute พร้อมกับ :other ไม่ได้',
    'regex' => 'รูปแบบของ :attribute ไม่ถูกต้อง',
    'required' => 'กรุณากรอก :attribute',
    'required_array_keys' => ':attribute ต้องมีค่าต่อไปนี้: :values',
    'required_if' => 'กรุณากรอก :attribute เมื่อ :other เป็น :value',
    'required_if_accepted' => 'กรุณากรอก :attribute เมื่อยอมรับ :other',
    'required_unless' => 'กรุณากรอก :attribute เว้นแต่ :other เป็นค่าใดค่าหนึ่งต่อไปนี้: :values',
    'required_with' => 'กรุณากรอก :attribute เมื่อกรอก :values',
    'required_with_all' => 'กรุณากรอก :attribute เมื่อกรอก :values ครบทั้งหมด',
    'required_without' => 'กรุณากรอก :attribute เมื่อไม่ได้กรอก :values',
    'required_without_all' => 'กรุณากรอก :attribute เมื่อไม่ได้กรอกช่องใดเลยต่อไปนี้: :values',
    'same' => ':attribute และ :other ต้องตรงกัน',
    'size' => [
        'array' => ':attribute ต้องมี :size รายการ',
        'file' => 'ไฟล์ :attribute ต้องมีขนาด :size กิโลไบต์',
        'numeric' => ':attribute ต้องเท่ากับ :size',
        'string' => ':attribute ต้องมี :size ตัวอักษร',
    ],
    'starts_with' => ':attribute ต้องขึ้นต้นด้วยค่าใดค่าหนึ่งต่อไปนี้: :values',
    'string' => ':attribute ต้องเป็นข้อความ',
    'timezone' => ':attribute ต้องเป็นเขตเวลาที่ถูกต้อง',
    'unique' => ':attribute นี้ถูกใช้ไปแล้ว',
    'uploaded' => 'อัปโหลด :attribute ไม่สำเร็จ',
    'url' => ':attribute ต้องเป็นที่อยู่เว็บที่ถูกต้อง',
    'uuid' => ':attribute ต้องเป็น UUID ที่ถูกต้อง',
    'phone' => ':attribute ต้องเป็นหมายเลขโทรศัพท์ที่ถูกต้อง',

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
            'exists' => 'รายการนี้ไม่มีให้ใช้งานแล้ว',
        ],
        'parent_id' => [
            'exists' => 'ความคิดเห็นที่คุณกำลังตอบกลับไม่มีให้ใช้งานแล้ว',
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
        'username' => 'ชื่อผู้ใช้',
        'email' => 'อีเมล',
        'password' => 'รหัสผ่าน',
        'password_confirmation' => 'ยืนยันรหัสผ่าน',
        'cgu' => 'ข้อกำหนดในการให้บริการ',
        'title' => 'ชื่อเรื่อง',
        'description' => 'คำอธิบาย',
        'category_id' => 'หมวดหมู่',
        'language' => 'ภาษา',
        'name' => 'ชื่อ',
        'message' => 'ข้อความ',
        'avatar' => 'รูปโปรไฟล์',
        'banner' => 'แบนเนอร์',
        'first_name' => 'ชื่อจริง',
        'last_name' => 'นามสกุล',
        'city' => 'เมือง',
        'country' => 'ประเทศ',
        'address' => 'ที่อยู่',
        'phone' => 'โทรศัพท์',
        'mobile' => 'โทรศัพท์มือถือ',
        'age' => 'อายุ',
        'sex' => 'เพศ',
        'gender' => 'เพศ',
        'day' => 'วัน',
        'month' => 'เดือน',
        'year' => 'ปี',
        'hour' => 'ชั่วโมง',
        'minute' => 'นาที',
        'second' => 'วินาที',
        'content' => 'เนื้อหา',
        'excerpt' => 'ข้อความย่อ',
        'date' => 'วันที่',
        'time' => 'เวลา',
        'available' => 'สถานะพร้อมใช้งาน',
        'size' => 'ขนาด',
        'comment' => 'ความคิดเห็น',
        'reason' => 'เหตุผล',
        'subject' => 'หัวเรื่อง',
        'body' => 'เนื้อหาอีเมล',
        'to' => 'ผู้รับ',
        'slug' => 'slug',
        'meta_title' => 'ชื่อเรื่องเมตา',
        'meta_description' => 'คำอธิบายเมตา',
        'sort_order' => 'ลำดับการเรียง',
        'price' => 'ราคา',
        'duration' => 'ระยะเวลา',
        'days' => 'จำนวนวัน',
        'ip_address' => 'ที่อยู่ IP',
        'image_url' => 'ที่อยู่รูปภาพ',
        'link_url' => 'ลิงก์',
        'starts_at' => 'เวลาเริ่ม',
        'expires_at' => 'เวลาสิ้นสุด',
        'source' => 'ที่อยู่ต้นทาง',
        'destination' => 'ที่อยู่ปลายทาง',
        'icon' => 'ไอคอน',
        'file' => 'ไฟล์',
        'label' => 'ชื่อที่แสดง',
        'code' => 'รหัส',
        'website' => 'เว็บไซต์',
        'user_id' => 'หมายเลขสมาชิก',
        'video_id' => 'หมายเลขวิดีโอ',
        'thumbnail_file' => 'ภาพขนาดย่อ',
        'thumbnail' => 'ภาพขนาดย่อ',
        'pages' => 'จำนวนหน้า',
        'start_page' => 'หน้าเริ่มต้น',
        'pagesize' => 'จำนวนวิดีโอต่อหน้า',
        'delay' => 'ระยะหน่วงเวลา',
        'updated_hours' => 'จำนวนชั่วโมงหลังการอัปเดต',
    ],

];
