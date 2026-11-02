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

    'accepted' => ':attribute 항목에 동의해야 합니다.',
    'accepted_if' => ':other 항목 값이 :value일 때는 :attribute 항목에 동의해야 합니다.',
    'active_url' => ':attribute 항목은 올바른 웹 주소가 아닙니다.',
    'after' => ':attribute 항목은 :date 이후의 날짜여야 합니다.',
    'after_or_equal' => ':attribute 항목은 :date 또는 그 이후의 날짜여야 합니다.',
    'alpha' => ':attribute 항목에는 문자만 사용할 수 있습니다.',
    'alpha_dash' => ':attribute 항목에는 문자, 숫자, 하이픈, 밑줄만 사용할 수 있습니다.',
    'alpha_num' => ':attribute 항목에는 문자와 숫자만 사용할 수 있습니다.',
    'array' => ':attribute 항목은 목록이어야 합니다.',
    'before' => ':attribute 항목은 :date 이전의 날짜여야 합니다.',
    'before_or_equal' => ':attribute 항목은 :date 또는 그 이전의 날짜여야 합니다.',
    'between' => [
        'array' => ':attribute 항목은 :min개에서 :max개 사이여야 합니다.',
        'file' => ':attribute 파일 크기는 :min~:max킬로바이트여야 합니다.',
        'numeric' => ':attribute 항목은 :min에서 :max 사이여야 합니다.',
        'string' => ':attribute 항목은 :min~:max자여야 합니다.',
    ],
    'boolean' => ':attribute 항목은 예 또는 아니요여야 합니다.',
    'confirmed' => ':attribute 항목이 확인 입력과 일치하지 않습니다.',
    'current_password' => '비밀번호가 올바르지 않습니다.',
    'date' => ':attribute 항목은 올바른 날짜가 아닙니다.',
    'date_equals' => ':attribute 항목은 :date 날짜여야 합니다.',
    'date_format' => ':attribute 항목이 :format 형식과 맞지 않습니다.',
    'declined' => ':attribute 항목을 거부해야 합니다.',
    'declined_if' => ':other 항목 값이 :value일 때는 :attribute 항목을 거부해야 합니다.',
    'different' => ':attribute 항목과 :other 항목은 서로 달라야 합니다.',
    'digits' => ':attribute 항목은 :digits자리 숫자여야 합니다.',
    'digits_between' => ':attribute 항목은 :min~:max자리 숫자여야 합니다.',
    'dimensions' => ':attribute 이미지 크기가 맞지 않습니다.',
    'distinct' => ':attribute 항목에 중복된 값이 있습니다.',
    'doesnt_end_with' => ':attribute 항목은 다음 값으로 끝날 수 없습니다: :values.',
    'doesnt_start_with' => ':attribute 항목은 다음 값으로 시작할 수 없습니다: :values.',
    'email' => 'name@example.com과 같은 올바른 이메일 주소를 입력하세요.',
    'ends_with' => ':attribute 항목은 다음 값 중 하나로 끝나야 합니다: :values.',
    'enum' => ':attribute 항목에서 선택한 값이 올바르지 않습니다.',
    'exists' => ':attribute 항목에서 선택한 값을 찾을 수 없습니다.',
    'file' => ':attribute 항목은 파일이어야 합니다.',
    'filled' => ':attribute 항목은 비워 둘 수 없습니다.',
    'gt' => [
        'array' => ':attribute 항목은 :value개보다 많아야 합니다.',
        'file' => ':attribute 파일 크기는 :value킬로바이트보다 커야 합니다.',
        'numeric' => ':attribute 항목은 :value보다 커야 합니다.',
        'string' => ':attribute 항목은 :value자보다 길어야 합니다.',
    ],
    'gte' => [
        'array' => ':attribute 항목은 :value개 이상이어야 합니다.',
        'file' => ':attribute 파일 크기는 :value킬로바이트 이상이어야 합니다.',
        'numeric' => ':attribute 항목은 :value 이상이어야 합니다.',
        'string' => ':attribute 항목은 :value자 이상이어야 합니다.',
    ],
    'image' => ':attribute 항목은 이미지여야 합니다.',
    'in' => ':attribute 항목에서 선택한 값이 올바르지 않습니다.',
    'in_array' => ':attribute 항목은 :other 항목의 값 중 하나여야 합니다.',
    'integer' => ':attribute 항목은 정수여야 합니다.',
    'ip' => ':attribute 항목은 올바른 IP 주소여야 합니다.',
    'ipv4' => ':attribute 항목은 올바른 IPv4 주소여야 합니다.',
    'ipv6' => ':attribute 항목은 올바른 IPv6 주소여야 합니다.',
    'json' => ':attribute 항목은 올바른 JSON 텍스트여야 합니다.',
    'lt' => [
        'array' => ':attribute 항목은 :value개보다 적어야 합니다.',
        'file' => ':attribute 파일 크기는 :value킬로바이트보다 작아야 합니다.',
        'numeric' => ':attribute 항목은 :value보다 작아야 합니다.',
        'string' => ':attribute 항목은 :value자보다 짧아야 합니다.',
    ],
    'lte' => [
        'array' => ':attribute 항목은 :value개 이하여야 합니다.',
        'file' => ':attribute 파일 크기는 :value킬로바이트 이하여야 합니다.',
        'numeric' => ':attribute 항목은 :value 이하여야 합니다.',
        'string' => ':attribute 항목은 :value자 이하여야 합니다.',
    ],
    'mac_address' => ':attribute 항목은 올바른 MAC 주소여야 합니다.',
    'max' => [
        'array' => ':attribute 항목은 :max개를 넘을 수 없습니다.',
        'file' => ':attribute 파일 크기는 :max킬로바이트를 넘을 수 없습니다.',
        'numeric' => ':attribute 항목은 :max보다 클 수 없습니다.',
        'string' => ':attribute 항목은 :max자를 넘을 수 없습니다.',
    ],
    'max_digits' => ':attribute 항목은 :max자리를 넘을 수 없습니다.',
    'mimes' => ':attribute 항목은 다음 형식의 파일이어야 합니다: :values.',
    'mimetypes' => ':attribute 항목은 다음 형식의 파일이어야 합니다: :values.',
    'min' => [
        'array' => ':attribute 항목은 :min개 이상이어야 합니다.',
        'file' => ':attribute 파일 크기는 :min킬로바이트 이상이어야 합니다.',
        'numeric' => ':attribute 항목은 :min 이상이어야 합니다.',
        'string' => ':attribute 항목은 :min자 이상이어야 합니다.',
    ],
    'min_digits' => ':attribute 항목은 :min자리 이상이어야 합니다.',
    'multiple_of' => ':attribute 항목은 :value의 배수여야 합니다.',
    'not_in' => ':attribute 항목에서 선택한 값이 올바르지 않습니다.',
    'not_regex' => ':attribute 항목의 형식이 올바르지 않습니다.',
    'numeric' => ':attribute 항목은 숫자여야 합니다.',
    'password' => [
        'letters' => ':attribute 항목에는 문자가 하나 이상 있어야 합니다.',
        'mixed' => ':attribute 항목에는 대문자와 소문자가 각각 하나 이상 있어야 합니다.',
        'numbers' => ':attribute 항목에는 숫자가 하나 이상 있어야 합니다.',
        'symbols' => ':attribute 항목에는 기호가 하나 이상 있어야 합니다.',
        'uncompromised' => '이 :attribute 값은 데이터 유출에서 발견되었습니다. 다른 값을 선택하세요.',
    ],
    'present' => ':attribute 항목이 있어야 합니다.',
    'prohibited' => ':attribute 항목은 입력할 수 없습니다.',
    'prohibited_if' => ':other 항목 값이 :value일 때는 :attribute 항목을 입력할 수 없습니다.',
    'prohibited_unless' => ':attribute 항목은 :other 항목 값이 다음 중 하나일 때만 입력할 수 있습니다: :values.',
    'prohibits' => ':attribute 항목은 :other 항목과 함께 입력할 수 없습니다.',
    'regex' => ':attribute 항목의 형식이 올바르지 않습니다.',
    'required' => ':attribute 항목은 필수입니다.',
    'required_array_keys' => ':attribute 항목에는 다음 값이 있어야 합니다: :values.',
    'required_if' => ':other 항목 값이 :value일 때는 :attribute 항목이 필수입니다.',
    'required_if_accepted' => ':other 항목에 동의한 경우 :attribute 항목이 필수입니다.',
    'required_unless' => ':other 항목 값이 다음 중 하나가 아니면 :attribute 항목이 필수입니다: :values.',
    'required_with' => ':values 항목을 입력한 경우 :attribute 항목이 필수입니다.',
    'required_with_all' => ':values 항목을 모두 입력한 경우 :attribute 항목이 필수입니다.',
    'required_without' => ':values 항목을 입력하지 않은 경우 :attribute 항목이 필수입니다.',
    'required_without_all' => '다음 항목을 하나도 입력하지 않은 경우 :attribute 항목이 필수입니다: :values.',
    'same' => ':attribute 항목과 :other 항목이 일치해야 합니다.',
    'size' => [
        'array' => ':attribute 항목은 :size개여야 합니다.',
        'file' => ':attribute 파일 크기는 :size킬로바이트여야 합니다.',
        'numeric' => ':attribute 항목의 값은 :size와(과) 같아야 합니다.',
        'string' => ':attribute 항목은 :size자여야 합니다.',
    ],
    'starts_with' => ':attribute 항목은 다음 값 중 하나로 시작해야 합니다: :values.',
    'string' => ':attribute 항목은 텍스트여야 합니다.',
    'timezone' => ':attribute 항목은 올바른 시간대여야 합니다.',
    'unique' => '이 :attribute 값은 이미 사용 중입니다.',
    'uploaded' => ':attribute 항목을 업로드하지 못했습니다.',
    'url' => ':attribute 항목은 올바른 웹 주소여야 합니다.',
    'uuid' => ':attribute 항목은 올바른 UUID여야 합니다.',
    'phone' => ':attribute 항목은 올바른 전화번호여야 합니다.',

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
            'exists' => '더 이상 이용할 수 없는 항목입니다.',
        ],
        'parent_id' => [
            'exists' => '답글을 달려는 댓글을 더 이상 이용할 수 없습니다.',
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
        'username' => '사용자명',
        'email' => '이메일',
        'password' => '비밀번호',
        'password_confirmation' => '비밀번호 확인',
        'cgu' => '서비스 약관',
        'title' => '제목',
        'description' => '설명',
        'category_id' => '카테고리',
        'language' => '언어',
        'name' => '이름',
        'message' => '메시지',
        'avatar' => '프로필 사진',
        'banner' => '배너',
        'first_name' => '이름',
        'last_name' => '성',
        'city' => '도시',
        'country' => '국가',
        'address' => '주소',
        'phone' => '전화번호',
        'mobile' => '휴대전화 번호',
        'age' => '나이',
        'sex' => '성별',
        'gender' => '성별',
        'day' => '일',
        'month' => '월',
        'year' => '연도',
        'hour' => '시',
        'minute' => '분',
        'second' => '초',
        'content' => '내용',
        'excerpt' => '발췌',
        'date' => '날짜',
        'time' => '시간',
        'available' => '사용 가능 여부',
        'size' => '크기',
        'comment' => '의견',
        'reason' => '사유',
        'subject' => '제목',
        'body' => '본문',
        'to' => '받는 사람',
        'slug' => '슬러그',
        'meta_title' => '메타 제목',
        'meta_description' => '메타 설명',
        'sort_order' => '정렬 순서',
        'price' => '가격',
        'duration' => '기간',
        'days' => '일수',
        'ip_address' => 'IP 주소',
        'image_url' => '이미지 주소',
        'link_url' => '링크',
        'starts_at' => '시작 일시',
        'expires_at' => '종료 일시',
        'source' => '원래 주소',
        'destination' => '이동할 주소',
        'icon' => '아이콘',
        'file' => '파일',
        'label' => '표시 이름',
        'code' => '코드',
        'website' => '웹사이트',
        'user_id' => '회원 번호',
        'video_id' => '동영상 번호',
        'thumbnail_file' => '미리보기 이미지',
        'thumbnail' => '미리보기 이미지',
        'pages' => '페이지 수',
        'start_page' => '시작 페이지',
        'pagesize' => '페이지당 동영상 수',
        'delay' => '대기 시간',
        'updated_hours' => '업데이트 후 시간',
    ],

];
