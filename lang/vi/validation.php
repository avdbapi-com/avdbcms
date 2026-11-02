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

    'accepted' => 'Bạn cần chấp nhận :attribute.',
    'accepted_if' => 'Bạn cần chấp nhận :attribute khi :other là :value.',
    'active_url' => ':Attribute không phải là địa chỉ web hợp lệ.',
    'after' => ':Attribute phải là ngày sau :date.',
    'after_or_equal' => ':Attribute phải là ngày :date hoặc sau đó.',
    'alpha' => ':Attribute chỉ được chứa chữ cái.',
    'alpha_dash' => ':Attribute chỉ được chứa chữ cái, số, dấu gạch ngang và gạch dưới.',
    'alpha_num' => ':Attribute chỉ được chứa chữ cái và số.',
    'array' => ':Attribute phải là một danh sách.',
    'before' => ':Attribute phải là ngày trước :date.',
    'before_or_equal' => ':Attribute phải là ngày :date hoặc trước đó.',
    'between' => [
        'array' => ':Attribute phải có từ :min đến :max mục.',
        'file' => 'Tệp :attribute phải có dung lượng từ :min đến :max kilobyte.',
        'numeric' => ':Attribute phải nằm trong khoảng từ :min đến :max.',
        'string' => ':Attribute phải có từ :min đến :max ký tự.',
    ],
    'boolean' => ':Attribute phải là có hoặc không.',
    'confirmed' => ':Attribute không khớp với phần xác nhận.',
    'current_password' => 'Mật khẩu không đúng.',
    'date' => ':Attribute không phải là ngày hợp lệ.',
    'date_equals' => ':Attribute phải là ngày :date.',
    'date_format' => ':Attribute không đúng định dạng :format.',
    'declined' => 'Bạn cần từ chối :attribute.',
    'declined_if' => 'Bạn cần từ chối :attribute khi :other là :value.',
    'different' => ':Attribute và :other phải khác nhau.',
    'digits' => ':Attribute phải có :digits chữ số.',
    'digits_between' => ':Attribute phải có từ :min đến :max chữ số.',
    'dimensions' => 'Kích thước ảnh :attribute không phù hợp.',
    'distinct' => ':Attribute có giá trị bị trùng.',
    'doesnt_end_with' => ':Attribute không được kết thúc bằng một trong các giá trị sau: :values.',
    'doesnt_start_with' => ':Attribute không được bắt đầu bằng một trong các giá trị sau: :values.',
    'email' => 'Hãy nhập địa chỉ email hợp lệ, ví dụ name@example.com.',
    'ends_with' => ':Attribute phải kết thúc bằng một trong các giá trị sau: :values.',
    'enum' => 'Giá trị đã chọn cho :attribute không hợp lệ.',
    'exists' => 'Không tìm thấy giá trị đã chọn cho :attribute.',
    'file' => ':Attribute phải là một tệp.',
    'filled' => ':Attribute không được để trống.',
    'gt' => [
        'array' => ':Attribute phải có nhiều hơn :value mục.',
        'file' => 'Tệp :attribute phải lớn hơn :value kilobyte.',
        'numeric' => ':Attribute phải lớn hơn :value.',
        'string' => ':Attribute phải có nhiều hơn :value ký tự.',
    ],
    'gte' => [
        'array' => ':Attribute phải có ít nhất :value mục.',
        'file' => 'Tệp :attribute phải có dung lượng ít nhất :value kilobyte.',
        'numeric' => ':Attribute phải lớn hơn hoặc bằng :value.',
        'string' => ':Attribute phải có ít nhất :value ký tự.',
    ],
    'image' => ':Attribute phải là hình ảnh.',
    'in' => 'Giá trị đã chọn cho :attribute không hợp lệ.',
    'in_array' => ':Attribute phải là một trong các giá trị của :other.',
    'integer' => ':Attribute phải là số nguyên.',
    'ip' => ':Attribute phải là địa chỉ IP hợp lệ.',
    'ipv4' => ':Attribute phải là địa chỉ IPv4 hợp lệ.',
    'ipv6' => ':Attribute phải là địa chỉ IPv6 hợp lệ.',
    'json' => ':Attribute phải là văn bản JSON hợp lệ.',
    'lt' => [
        'array' => ':Attribute phải có ít hơn :value mục.',
        'file' => 'Tệp :attribute phải nhỏ hơn :value kilobyte.',
        'numeric' => ':Attribute phải nhỏ hơn :value.',
        'string' => ':Attribute phải có ít hơn :value ký tự.',
    ],
    'lte' => [
        'array' => ':Attribute không được có nhiều hơn :value mục.',
        'file' => 'Tệp :attribute không được lớn hơn :value kilobyte.',
        'numeric' => ':Attribute phải nhỏ hơn hoặc bằng :value.',
        'string' => ':Attribute không được dài hơn :value ký tự.',
    ],
    'mac_address' => ':Attribute phải là địa chỉ MAC hợp lệ.',
    'max' => [
        'array' => ':Attribute không được có nhiều hơn :max mục.',
        'file' => 'Tệp :attribute không được lớn hơn :max kilobyte.',
        'numeric' => ':Attribute không được lớn hơn :max.',
        'string' => ':Attribute không được dài hơn :max ký tự.',
    ],
    'max_digits' => ':Attribute không được có nhiều hơn :max chữ số.',
    'mimes' => ':Attribute phải là tệp thuộc một trong các loại: :values.',
    'mimetypes' => ':Attribute phải là tệp thuộc một trong các loại: :values.',
    'min' => [
        'array' => ':Attribute phải có ít nhất :min mục.',
        'file' => 'Tệp :attribute phải có dung lượng ít nhất :min kilobyte.',
        'numeric' => ':Attribute phải ít nhất là :min.',
        'string' => ':Attribute phải có ít nhất :min ký tự.',
    ],
    'min_digits' => ':Attribute phải có ít nhất :min chữ số.',
    'multiple_of' => ':Attribute phải là bội số của :value.',
    'not_in' => 'Giá trị đã chọn cho :attribute không hợp lệ.',
    'not_regex' => 'Định dạng của :attribute không hợp lệ.',
    'numeric' => ':Attribute phải là một số.',
    'password' => [
        'letters' => ':Attribute phải có ít nhất một chữ cái.',
        'mixed' => ':Attribute phải có ít nhất một chữ hoa và một chữ thường.',
        'numbers' => ':Attribute phải có ít nhất một chữ số.',
        'symbols' => ':Attribute phải có ít nhất một ký hiệu.',
        'uncompromised' => ':Attribute này đã xuất hiện trong một vụ lộ dữ liệu. Hãy chọn cái khác.',
    ],
    'present' => ':Attribute phải có mặt.',
    'prohibited' => 'Không được điền :attribute.',
    'prohibited_if' => 'Không được điền :attribute khi :other là :value.',
    'prohibited_unless' => 'Chỉ được điền :attribute khi :other là một trong các giá trị sau: :values.',
    'prohibits' => 'Không được điền :attribute cùng với :other.',
    'regex' => 'Định dạng của :attribute không hợp lệ.',
    'required' => ':Attribute là bắt buộc.',
    'required_array_keys' => ':Attribute phải có các giá trị sau: :values.',
    'required_if' => ':Attribute là bắt buộc khi :other là :value.',
    'required_if_accepted' => ':Attribute là bắt buộc khi :other được chấp nhận.',
    'required_unless' => ':Attribute là bắt buộc trừ khi :other là một trong các giá trị sau: :values.',
    'required_with' => ':Attribute là bắt buộc khi đã điền :values.',
    'required_with_all' => ':Attribute là bắt buộc khi đã điền tất cả :values.',
    'required_without' => ':Attribute là bắt buộc khi chưa điền :values.',
    'required_without_all' => ':Attribute là bắt buộc khi chưa điền mục nào trong số: :values.',
    'same' => ':Attribute và :other phải giống nhau.',
    'size' => [
        'array' => ':Attribute phải có :size mục.',
        'file' => 'Tệp :attribute phải có dung lượng :size kilobyte.',
        'numeric' => ':Attribute phải bằng :size.',
        'string' => ':Attribute phải có :size ký tự.',
    ],
    'starts_with' => ':Attribute phải bắt đầu bằng một trong các giá trị sau: :values.',
    'string' => ':Attribute phải là văn bản.',
    'timezone' => ':Attribute phải là múi giờ hợp lệ.',
    'unique' => ':Attribute này đã được sử dụng.',
    'uploaded' => 'Không tải lên được :attribute.',
    'url' => ':Attribute phải là địa chỉ web hợp lệ.',
    'uuid' => ':Attribute phải là UUID hợp lệ.',
    'phone' => ':Attribute phải là số điện thoại hợp lệ.',

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
            'exists' => 'Nội dung này không còn nữa.',
        ],
        'parent_id' => [
            'exists' => 'Bình luận bạn đang trả lời không còn nữa.',
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
        'username' => 'tên người dùng',
        'email' => 'email',
        'password' => 'mật khẩu',
        'password_confirmation' => 'xác nhận mật khẩu',
        'cgu' => 'điều khoản dịch vụ',
        'title' => 'tiêu đề',
        'description' => 'mô tả',
        'category_id' => 'danh mục',
        'language' => 'ngôn ngữ',
        'name' => 'tên',
        'message' => 'tin nhắn',
        'avatar' => 'ảnh đại diện',
        'banner' => 'biểu ngữ',
        'first_name' => 'tên',
        'last_name' => 'họ',
        'city' => 'thành phố',
        'country' => 'quốc gia',
        'address' => 'địa chỉ',
        'phone' => 'số điện thoại',
        'mobile' => 'số di động',
        'age' => 'tuổi',
        'sex' => 'giới tính',
        'gender' => 'giới tính',
        'day' => 'ngày',
        'month' => 'tháng',
        'year' => 'năm',
        'hour' => 'giờ',
        'minute' => 'phút',
        'second' => 'giây',
        'content' => 'nội dung',
        'excerpt' => 'đoạn trích',
        'date' => 'ngày',
        'time' => 'thời gian',
        'available' => 'trạng thái sẵn có',
        'size' => 'kích thước',
        'comment' => 'ghi chú',
        'reason' => 'lý do',
        'subject' => 'chủ đề',
        'body' => 'nội dung thư',
        'to' => 'người nhận',
        'slug' => 'đường dẫn tĩnh',
        'meta_title' => 'tiêu đề meta',
        'meta_description' => 'mô tả meta',
        'sort_order' => 'thứ tự sắp xếp',
        'price' => 'giá',
        'duration' => 'thời hạn',
        'days' => 'số ngày',
        'ip_address' => 'địa chỉ IP',
        'image_url' => 'địa chỉ hình ảnh',
        'link_url' => 'liên kết',
        'starts_at' => 'thời điểm bắt đầu',
        'expires_at' => 'thời điểm kết thúc',
        'source' => 'địa chỉ nguồn',
        'destination' => 'địa chỉ đích',
        'icon' => 'biểu tượng',
        'file' => 'tệp',
        'label' => 'tên hiển thị',
        'code' => 'mã',
        'website' => 'trang web',
        'user_id' => 'mã thành viên',
        'video_id' => 'mã video',
        'thumbnail_file' => 'hình thu nhỏ',
        'thumbnail' => 'hình thu nhỏ',
        'pages' => 'số trang',
        'start_page' => 'trang bắt đầu',
        'pagesize' => 'số video mỗi trang',
        'delay' => 'độ trễ',
        'updated_hours' => 'số giờ kể từ khi cập nhật',
    ],

];
