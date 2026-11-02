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

    'accepted' => ':attributeに同意してください。',
    'accepted_if' => ':otherが:valueのときは、:attributeに同意してください。',
    'active_url' => ':attributeは有効なウェブアドレスではありません。',
    'after' => ':attributeには:dateより後の日付を指定してください。',
    'after_or_equal' => ':attributeには:date以降の日付を指定してください。',
    'alpha' => ':attributeには文字だけを使ってください。',
    'alpha_dash' => ':attributeには文字、数字、ハイフン、アンダースコアだけを使ってください。',
    'alpha_num' => ':attributeには文字と数字だけを使ってください。',
    'array' => ':attributeはリストにしてください。',
    'before' => ':attributeには:dateより前の日付を指定してください。',
    'before_or_equal' => ':attributeには:date以前の日付を指定してください。',
    'between' => [
        'array' => ':attributeの項目は:min～:max個にしてください。',
        'file' => ':attributeのファイルサイズは:min～:maxキロバイトにしてください。',
        'numeric' => ':attributeには:min～:maxの値を指定してください。',
        'string' => ':attributeは:min～:max文字で入力してください。',
    ],
    'boolean' => ':attributeは「はい」か「いいえ」で指定してください。',
    'confirmed' => ':attributeが確認用の入力と一致しません。',
    'current_password' => 'パスワードが違います。',
    'date' => ':attributeは有効な日付ではありません。',
    'date_equals' => ':attributeには:dateの日付を指定してください。',
    'date_format' => ':attributeが:formatの形式と一致しません。',
    'declined' => ':attributeを拒否してください。',
    'declined_if' => ':otherが:valueのときは、:attributeを拒否してください。',
    'different' => ':attributeと:otherには違う値を指定してください。',
    'digits' => ':attributeは:digits桁で入力してください。',
    'digits_between' => ':attributeは:min～:max桁で入力してください。',
    'dimensions' => ':attributeの画像サイズが合っていません。',
    'distinct' => ':attributeに重複した値があります。',
    'doesnt_end_with' => ':attributeの末尾には次の値を使えません：:values',
    'doesnt_start_with' => ':attributeの先頭には次の値を使えません：:values',
    'email' => 'name@example.com のような正しいメールアドレスを入力してください。',
    'ends_with' => ':attributeは次のいずれかで終わる必要があります：:values',
    'enum' => ':attributeで選ばれた値は無効です。',
    'exists' => ':attributeで選ばれた値が見つかりません。',
    'file' => ':attributeにはファイルを指定してください。',
    'filled' => ':attributeは空にできません。',
    'gt' => [
        'array' => ':attributeの項目は:value個より多くしてください。',
        'file' => ':attributeのファイルサイズは:valueキロバイトより大きくしてください。',
        'numeric' => ':attributeには:valueより大きい値を指定してください。',
        'string' => ':attributeは:value文字より多く入力してください。',
    ],
    'gte' => [
        'array' => ':attributeの項目は:value個以上にしてください。',
        'file' => ':attributeのファイルサイズは:valueキロバイト以上にしてください。',
        'numeric' => ':attributeには:value以上の値を指定してください。',
        'string' => ':attributeは:value文字以上で入力してください。',
    ],
    'image' => ':attributeには画像を指定してください。',
    'in' => ':attributeで選ばれた値は無効です。',
    'in_array' => ':attributeは:otherの値のいずれかにしてください。',
    'integer' => ':attributeには整数を指定してください。',
    'ip' => ':attributeには有効なIPアドレスを指定してください。',
    'ipv4' => ':attributeには有効なIPv4アドレスを指定してください。',
    'ipv6' => ':attributeには有効なIPv6アドレスを指定してください。',
    'json' => ':attributeには有効なJSONテキストを指定してください。',
    'lt' => [
        'array' => ':attributeの項目は:value個より少なくしてください。',
        'file' => ':attributeのファイルサイズは:valueキロバイトより小さくしてください。',
        'numeric' => ':attributeには:valueより小さい値を指定してください。',
        'string' => ':attributeは:value文字より少なく入力してください。',
    ],
    'lte' => [
        'array' => ':attributeの項目は:value個以下にしてください。',
        'file' => ':attributeのファイルサイズは:valueキロバイト以下にしてください。',
        'numeric' => ':attributeには:value以下の値を指定してください。',
        'string' => ':attributeは:value文字以下で入力してください。',
    ],
    'mac_address' => ':attributeには有効なMACアドレスを指定してください。',
    'max' => [
        'array' => ':attributeの項目は:max個以下にしてください。',
        'file' => ':attributeのファイルサイズは:maxキロバイト以下にしてください。',
        'numeric' => ':attributeには:max以下の値を指定してください。',
        'string' => ':attributeは:max文字以下で入力してください。',
    ],
    'max_digits' => ':attributeは:max桁以下にしてください。',
    'mimes' => ':attributeには次の形式のファイルを指定してください：:values',
    'mimetypes' => ':attributeには次の形式のファイルを指定してください：:values',
    'min' => [
        'array' => ':attributeの項目は:min個以上にしてください。',
        'file' => ':attributeのファイルサイズは:minキロバイト以上にしてください。',
        'numeric' => ':attributeには:min以上の値を指定してください。',
        'string' => ':attributeは:min文字以上で入力してください。',
    ],
    'min_digits' => ':attributeは:min桁以上にしてください。',
    'multiple_of' => ':attributeには:valueの倍数を指定してください。',
    'not_in' => ':attributeで選ばれた値は無効です。',
    'not_regex' => ':attributeの形式が正しくありません。',
    'numeric' => ':attributeには数値を指定してください。',
    'password' => [
        'letters' => ':attributeには文字を1つ以上含めてください。',
        'mixed' => ':attributeには大文字と小文字をそれぞれ1つ以上含めてください。',
        'numbers' => ':attributeには数字を1つ以上含めてください。',
        'symbols' => ':attributeには記号を1つ以上含めてください。',
        'uncompromised' => 'この:attributeはデータ漏えいで見つかっています。別のものを選んでください。',
    ],
    'present' => ':attributeが必要です。',
    'prohibited' => ':attributeは入力できません。',
    'prohibited_if' => ':otherが:valueのときは、:attributeは入力できません。',
    'prohibited_unless' => ':attributeは、:otherが次のいずれかのときだけ入力できます：:values',
    'prohibits' => ':attributeは:otherと一緒に入力できません。',
    'regex' => ':attributeの形式が正しくありません。',
    'required' => ':attributeを入力してください。',
    'required_array_keys' => ':attributeには次の値を含めてください：:values',
    'required_if' => ':otherが:valueのときは、:attributeを入力してください。',
    'required_if_accepted' => ':otherに同意したときは、:attributeを入力してください。',
    'required_unless' => ':otherが次のいずれかでない限り、:attributeを入力してください：:values',
    'required_with' => ':valuesを入力したときは、:attributeも入力してください。',
    'required_with_all' => ':valuesをすべて入力したときは、:attributeも入力してください。',
    'required_without' => ':valuesを入力しないときは、:attributeを入力してください。',
    'required_without_all' => '次のどれも入力しないときは、:attributeを入力してください：:values',
    'same' => ':attributeと:otherが一致しません。',
    'size' => [
        'array' => ':attributeの項目は:size個にしてください。',
        'file' => ':attributeのファイルサイズは:sizeキロバイトにしてください。',
        'numeric' => ':attributeには:sizeを指定してください。',
        'string' => ':attributeは:size文字で入力してください。',
    ],
    'starts_with' => ':attributeは次のいずれかで始まる必要があります：:values',
    'string' => ':attributeにはテキストを入力してください。',
    'timezone' => ':attributeには有効なタイムゾーンを指定してください。',
    'unique' => 'この:attributeはすでに使われています。',
    'uploaded' => ':attributeをアップロードできませんでした。',
    'url' => ':attributeには有効なウェブアドレスを指定してください。',
    'uuid' => ':attributeには有効なUUIDを指定してください。',
    'phone' => ':attributeには有効な電話番号を指定してください。',

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
            'exists' => 'この項目は利用できなくなりました。',
        ],
        'parent_id' => [
            'exists' => '返信先のコメントは利用できなくなりました。',
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
        'username' => 'ユーザー名',
        'email' => 'メールアドレス',
        'password' => 'パスワード',
        'password_confirmation' => 'パスワード（確認用）',
        'cgu' => '利用規約',
        'title' => 'タイトル',
        'description' => '説明',
        'category_id' => 'カテゴリ',
        'language' => '言語',
        'name' => '名前',
        'message' => 'メッセージ',
        'avatar' => 'プロフィール写真',
        'banner' => 'バナー',
        'first_name' => '名',
        'last_name' => '姓',
        'city' => '市区町村',
        'country' => '国',
        'address' => '住所',
        'phone' => '電話番号',
        'mobile' => '携帯電話番号',
        'age' => '年齢',
        'sex' => '性別',
        'gender' => '性別',
        'day' => '日',
        'month' => '月',
        'year' => '年',
        'hour' => '時',
        'minute' => '分',
        'second' => '秒',
        'content' => '内容',
        'excerpt' => '抜粋',
        'date' => '日付',
        'time' => '時刻',
        'available' => '利用可否',
        'size' => 'サイズ',
        'comment' => 'コメント',
        'reason' => '理由',
        'subject' => '件名',
        'body' => '本文',
        'to' => '宛先',
        'slug' => 'スラッグ',
        'meta_title' => 'メタタイトル',
        'meta_description' => 'メタディスクリプション',
        'sort_order' => '並び順',
        'price' => '価格',
        'duration' => '期間',
        'days' => '日数',
        'ip_address' => 'IPアドレス',
        'image_url' => '画像のアドレス',
        'link_url' => 'リンク',
        'starts_at' => '開始日時',
        'expires_at' => '終了日時',
        'source' => '転送元のアドレス',
        'destination' => '転送先のアドレス',
        'icon' => 'アイコン',
        'file' => 'ファイル',
        'label' => '表示名',
        'code' => 'コード',
        'website' => 'ウェブサイト',
        'user_id' => 'メンバー番号',
        'video_id' => '動画番号',
        'thumbnail_file' => 'サムネイル',
        'thumbnail' => 'サムネイル',
        'pages' => 'ページ数',
        'start_page' => '開始ページ',
        'pagesize' => '1ページあたりの動画数',
        'delay' => '待ち時間',
        'updated_hours' => '更新からの時間数',
    ],

];
