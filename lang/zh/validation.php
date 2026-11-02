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

    'accepted' => '请同意:attribute。',
    'accepted_if' => '当:other为 :value 时，请同意:attribute。',
    'active_url' => ':attribute不是有效的网址。',
    'after' => ':attribute必须是 :date 之后的日期。',
    'after_or_equal' => ':attribute必须是 :date 或之后的日期。',
    'alpha' => ':attribute只能包含字母。',
    'alpha_dash' => ':attribute只能包含字母、数字、连字符和下划线。',
    'alpha_num' => ':attribute只能包含字母和数字。',
    'array' => ':attribute必须是列表。',
    'before' => ':attribute必须是 :date 之前的日期。',
    'before_or_equal' => ':attribute必须是 :date 或之前的日期。',
    'between' => [
        'array' => ':attribute必须有 :min 到 :max 项。',
        'file' => ':attribute的文件大小必须在 :min 到 :max KB 之间。',
        'numeric' => ':attribute必须在 :min 到 :max 之间。',
        'string' => ':attribute必须为 :min 到 :max 个字符。',
    ],
    'boolean' => ':attribute必须为是或否。',
    'confirmed' => ':attribute与确认内容不一致。',
    'current_password' => '密码错误。',
    'date' => ':attribute不是有效的日期。',
    'date_equals' => ':attribute必须是 :date 这一天。',
    'date_format' => ':attribute不符合 :format 格式。',
    'declined' => '请拒绝:attribute。',
    'declined_if' => '当:other为 :value 时，请拒绝:attribute。',
    'different' => ':attribute和:other必须不同。',
    'digits' => ':attribute必须是 :digits 位数字。',
    'digits_between' => ':attribute必须是 :min 到 :max 位数字。',
    'dimensions' => ':attribute的图片尺寸不符合要求。',
    'distinct' => ':attribute中有重复的值。',
    'doesnt_end_with' => ':attribute不能以下列任一值结尾：:values。',
    'doesnt_start_with' => ':attribute不能以下列任一值开头：:values。',
    'email' => '请输入有效的邮箱地址，例如 name@example.com。',
    'ends_with' => ':attribute必须以下列任一值结尾：:values。',
    'enum' => '所选的:attribute无效。',
    'exists' => '找不到所选的:attribute。',
    'file' => ':attribute必须是文件。',
    'filled' => ':attribute不能为空。',
    'gt' => [
        'array' => ':attribute必须多于 :value 项。',
        'file' => ':attribute的文件大小必须大于 :value KB。',
        'numeric' => ':attribute必须大于 :value。',
        'string' => ':attribute必须多于 :value 个字符。',
    ],
    'gte' => [
        'array' => ':attribute至少需要 :value 项。',
        'file' => ':attribute的文件大小至少为 :value KB。',
        'numeric' => ':attribute必须大于或等于 :value。',
        'string' => ':attribute至少需要 :value 个字符。',
    ],
    'image' => ':attribute必须是图片。',
    'in' => '所选的:attribute无效。',
    'in_array' => ':attribute必须是:other中的一个值。',
    'integer' => ':attribute必须是整数。',
    'ip' => ':attribute必须是有效的 IP 地址。',
    'ipv4' => ':attribute必须是有效的 IPv4 地址。',
    'ipv6' => ':attribute必须是有效的 IPv6 地址。',
    'json' => ':attribute必须是有效的 JSON 文本。',
    'lt' => [
        'array' => ':attribute必须少于 :value 项。',
        'file' => ':attribute的文件大小必须小于 :value KB。',
        'numeric' => ':attribute必须小于 :value。',
        'string' => ':attribute必须少于 :value 个字符。',
    ],
    'lte' => [
        'array' => ':attribute不能多于 :value 项。',
        'file' => ':attribute的文件大小不能超过 :value KB。',
        'numeric' => ':attribute必须小于或等于 :value。',
        'string' => ':attribute不能超过 :value 个字符。',
    ],
    'mac_address' => ':attribute必须是有效的 MAC 地址。',
    'max' => [
        'array' => ':attribute不能多于 :max 项。',
        'file' => ':attribute的文件大小不能超过 :max KB。',
        'numeric' => ':attribute不能大于 :max。',
        'string' => ':attribute不能超过 :max 个字符。',
    ],
    'max_digits' => ':attribute不能超过 :max 位数字。',
    'mimes' => ':attribute必须是以下类型的文件：:values。',
    'mimetypes' => ':attribute必须是以下类型的文件：:values。',
    'min' => [
        'array' => ':attribute至少需要 :min 项。',
        'file' => ':attribute的文件大小至少为 :min KB。',
        'numeric' => ':attribute不能小于 :min。',
        'string' => ':attribute至少需要 :min 个字符。',
    ],
    'min_digits' => ':attribute至少需要 :min 位数字。',
    'multiple_of' => ':attribute必须是 :value 的倍数。',
    'not_in' => '所选的:attribute无效。',
    'not_regex' => ':attribute的格式无效。',
    'numeric' => ':attribute必须是数字。',
    'password' => [
        'letters' => ':attribute至少需要包含一个字母。',
        'mixed' => ':attribute至少需要包含一个大写字母和一个小写字母。',
        'numbers' => ':attribute至少需要包含一个数字。',
        'symbols' => ':attribute至少需要包含一个符号。',
        'uncompromised' => '此:attribute出现在数据泄露中，请换一个。',
    ],
    'present' => '必须提供:attribute。',
    'prohibited' => '不允许填写:attribute。',
    'prohibited_if' => '当:other为 :value 时，不允许填写:attribute。',
    'prohibited_unless' => '只有当:other为下列值之一时，才能填写:attribute：:values。',
    'prohibits' => ':attribute不能与:other同时填写。',
    'regex' => ':attribute的格式无效。',
    'required' => '请填写:attribute。',
    'required_array_keys' => ':attribute必须包含以下项：:values。',
    'required_if' => '当:other为 :value 时，请填写:attribute。',
    'required_if_accepted' => '同意:other时，请填写:attribute。',
    'required_unless' => '除非:other为下列值之一，否则请填写:attribute：:values。',
    'required_with' => '填写了 :values 时，请填写:attribute。',
    'required_with_all' => '填写了全部 :values 时，请填写:attribute。',
    'required_without' => '未填写 :values 时，请填写:attribute。',
    'required_without_all' => '以下各项都未填写时，请填写:attribute：:values。',
    'same' => ':attribute和:other必须一致。',
    'size' => [
        'array' => ':attribute必须有 :size 项。',
        'file' => ':attribute的文件大小必须为 :size KB。',
        'numeric' => ':attribute必须为 :size。',
        'string' => ':attribute必须为 :size 个字符。',
    ],
    'starts_with' => ':attribute必须以下列任一值开头：:values。',
    'string' => ':attribute必须是文本。',
    'timezone' => ':attribute必须是有效的时区。',
    'unique' => '此:attribute已被使用。',
    'uploaded' => ':attribute上传失败。',
    'url' => ':attribute必须是有效的网址。',
    'uuid' => ':attribute必须是有效的 UUID。',
    'phone' => ':attribute必须是有效的电话号码。',

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
            'exists' => '该内容已不可用。',
        ],
        'parent_id' => [
            'exists' => '您要回复的评论已不可用。',
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
        'username' => '用户名',
        'email' => '电子邮件',
        'password' => '密码',
        'password_confirmation' => '确认密码',
        'cgu' => '服务条款',
        'title' => '标题',
        'description' => '描述',
        'category_id' => '分类',
        'language' => '语言',
        'name' => '名称',
        'message' => '消息',
        'avatar' => '头像',
        'banner' => '横幅',
        'first_name' => '名字',
        'last_name' => '姓氏',
        'city' => '城市',
        'country' => '国家',
        'address' => '地址',
        'phone' => '电话',
        'mobile' => '手机号码',
        'age' => '年龄',
        'sex' => '性别',
        'gender' => '性别',
        'day' => '日',
        'month' => '月',
        'year' => '年份',
        'hour' => '小时',
        'minute' => '分钟',
        'second' => '秒',
        'content' => '内容',
        'excerpt' => '摘要',
        'date' => '日期',
        'time' => '时间',
        'available' => '可用状态',
        'size' => '大小',
        'comment' => '备注',
        'reason' => '原因',
        'subject' => '主题',
        'body' => '正文',
        'to' => '收件人',
        'slug' => '别名',
        'meta_title' => '元标题',
        'meta_description' => '元描述',
        'sort_order' => '排序',
        'price' => '价格',
        'duration' => '时长',
        'days' => '天数',
        'ip_address' => 'IP 地址',
        'image_url' => '图片地址',
        'link_url' => '链接',
        'starts_at' => '开始时间',
        'expires_at' => '结束时间',
        'source' => '原地址',
        'destination' => '目标地址',
        'icon' => '图标',
        'file' => '文件',
        'label' => '显示名称',
        'code' => '代码',
        'website' => '网站',
        'user_id' => '会员编号',
        'video_id' => '视频编号',
        'thumbnail_file' => '缩略图',
        'thumbnail' => '缩略图',
        'pages' => '页数',
        'start_page' => '起始页',
        'pagesize' => '每页视频数',
        'delay' => '延迟',
        'updated_hours' => '更新后的小时数',
    ],

];
