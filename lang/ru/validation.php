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

    'accepted' => 'Нужно принять «:attribute».',
    'accepted_if' => 'Нужно принять «:attribute», когда «:other» равно :value.',
    'active_url' => 'Поле «:attribute» должно содержать действительный веб-адрес.',
    'after' => 'Поле «:attribute» должно содержать дату после :date.',
    'after_or_equal' => 'Поле «:attribute» должно содержать дату :date или позже.',
    'alpha' => 'Поле «:attribute» может содержать только буквы.',
    'alpha_dash' => 'Поле «:attribute» может содержать только буквы, цифры, дефисы и подчёркивания.',
    'alpha_num' => 'Поле «:attribute» может содержать только буквы и цифры.',
    'array' => 'Поле «:attribute» должно быть списком.',
    'before' => 'Поле «:attribute» должно содержать дату до :date.',
    'before_or_equal' => 'Поле «:attribute» должно содержать дату :date или раньше.',
    'between' => [
        'array' => 'Количество элементов в поле «:attribute» должно быть от :min до :max.',
        'file' => 'Размер файла в поле «:attribute» должен быть от :min до :max КБ.',
        'numeric' => 'Значение поля «:attribute» должно быть от :min до :max.',
        'string' => 'Количество символов в поле «:attribute» должно быть от :min до :max.',
    ],
    'boolean' => 'Поле «:attribute» должно иметь значение «да» или «нет».',
    'confirmed' => 'Поле «:attribute» не совпадает с подтверждением.',
    'current_password' => 'Неверный пароль.',
    'date' => 'Поле «:attribute» должно содержать правильную дату.',
    'date_equals' => 'Поле «:attribute» должно содержать дату :date.',
    'date_format' => 'Поле «:attribute» не соответствует формату :format.',
    'declined' => 'Нужно отклонить «:attribute».',
    'declined_if' => 'Нужно отклонить «:attribute», когда «:other» равно :value.',
    'different' => 'Поля «:attribute» и «:other» должны различаться.',
    'digits' => 'Количество цифр в поле «:attribute» должно быть :digits.',
    'digits_between' => 'Количество цифр в поле «:attribute» должно быть от :min до :max.',
    'dimensions' => 'Размеры изображения в поле «:attribute» не подходят.',
    'distinct' => 'В поле «:attribute» есть повторяющееся значение.',
    'doesnt_end_with' => 'Поле «:attribute» не должно заканчиваться одним из этих значений: :values.',
    'doesnt_start_with' => 'Поле «:attribute» не должно начинаться с одного из этих значений: :values.',
    'email' => 'Введите действительный адрес электронной почты.',
    'ends_with' => 'Поле «:attribute» должно заканчиваться одним из этих значений: :values.',
    'enum' => 'Выбранное значение поля «:attribute» недопустимо.',
    'exists' => 'Выбранное значение поля «:attribute» не найдено.',
    'file' => 'Поле «:attribute» должно содержать файл.',
    'filled' => 'Поле «:attribute» не может быть пустым.',
    'gt' => [
        'array' => 'Количество элементов в поле «:attribute» должно быть больше :value.',
        'file' => 'Размер файла в поле «:attribute» должен быть больше :value КБ.',
        'numeric' => 'Значение поля «:attribute» должно быть больше :value.',
        'string' => 'Количество символов в поле «:attribute» должно быть больше :value.',
    ],
    'gte' => [
        'array' => 'Количество элементов в поле «:attribute» должно быть не меньше :value.',
        'file' => 'Размер файла в поле «:attribute» должен быть не меньше :value КБ.',
        'numeric' => 'Значение поля «:attribute» должно быть не меньше :value.',
        'string' => 'Количество символов в поле «:attribute» должно быть не меньше :value.',
    ],
    'image' => 'Поле «:attribute» должно содержать изображение.',
    'in' => 'Выбранное значение поля «:attribute» недопустимо.',
    'in_array' => 'Значение поля «:attribute» должно быть одним из значений поля «:other».',
    'integer' => 'Значение поля «:attribute» должно быть целым числом.',
    'ip' => 'Поле «:attribute» должно содержать действительный IP-адрес.',
    'ipv4' => 'Поле «:attribute» должно содержать действительный IPv4-адрес.',
    'ipv6' => 'Поле «:attribute» должно содержать действительный IPv6-адрес.',
    'json' => 'Поле «:attribute» должно содержать правильный текст JSON.',
    'lt' => [
        'array' => 'Количество элементов в поле «:attribute» должно быть меньше :value.',
        'file' => 'Размер файла в поле «:attribute» должен быть меньше :value КБ.',
        'numeric' => 'Значение поля «:attribute» должно быть меньше :value.',
        'string' => 'Количество символов в поле «:attribute» должно быть меньше :value.',
    ],
    'lte' => [
        'array' => 'Количество элементов в поле «:attribute» не должно превышать :value.',
        'file' => 'Размер файла в поле «:attribute» не должен превышать :value КБ.',
        'numeric' => 'Значение поля «:attribute» не должно превышать :value.',
        'string' => 'Количество символов в поле «:attribute» не должно превышать :value.',
    ],
    'mac_address' => 'Поле «:attribute» должно содержать действительный MAC-адрес.',
    'max' => [
        'array' => 'Количество элементов в поле «:attribute» не должно превышать :max.',
        'file' => 'Размер файла в поле «:attribute» не должен превышать :max КБ.',
        'numeric' => 'Значение поля «:attribute» не должно превышать :max.',
        'string' => 'Количество символов в поле «:attribute» не должно превышать :max.',
    ],
    'max_digits' => 'Количество цифр в поле «:attribute» не должно превышать :max.',
    'mimes' => 'Файл в поле «:attribute» должен быть одного из этих типов: :values.',
    'mimetypes' => 'Файл в поле «:attribute» должен быть одного из этих типов: :values.',
    'min' => [
        'array' => 'Количество элементов в поле «:attribute» должно быть не меньше :min.',
        'file' => 'Размер файла в поле «:attribute» должен быть не меньше :min КБ.',
        'numeric' => 'Значение поля «:attribute» должно быть не меньше :min.',
        'string' => 'Количество символов в поле «:attribute» должно быть не меньше :min.',
    ],
    'min_digits' => 'Количество цифр в поле «:attribute» должно быть не меньше :min.',
    'multiple_of' => 'Значение поля «:attribute» должно быть кратно :value.',
    'not_in' => 'Выбранное значение поля «:attribute» недопустимо.',
    'not_regex' => 'Поле «:attribute» имеет неверный формат.',
    'numeric' => 'Значение поля «:attribute» должно быть числом.',
    'password' => [
        'letters' => 'Поле «:attribute» должно содержать хотя бы одну букву.',
        'mixed' => 'Поле «:attribute» должно содержать хотя бы одну заглавную и одну строчную букву.',
        'numbers' => 'Поле «:attribute» должно содержать хотя бы одну цифру.',
        'symbols' => 'Поле «:attribute» должно содержать хотя бы один специальный символ.',
        'uncompromised' => 'Значение поля «:attribute» встречается в утечке данных. Выберите другое.',
    ],
    'present' => 'Поле «:attribute» должно присутствовать.',
    'prohibited' => 'Поле «:attribute» заполнять нельзя.',
    'prohibited_if' => 'Поле «:attribute» нельзя заполнять, когда «:other» равно :value.',
    'prohibited_unless' => 'Поле «:attribute» можно заполнять, только когда «:other» равно одному из значений: :values.',
    'prohibits' => 'Поле «:attribute» нельзя заполнять вместе с полем «:other».',
    'regex' => 'Поле «:attribute» имеет неверный формат.',
    'required' => 'Поле «:attribute» обязательно для заполнения.',
    'required_array_keys' => 'Поле «:attribute» должно содержать значения: :values.',
    'required_if' => 'Поле «:attribute» обязательно, когда «:other» равно :value.',
    'required_if_accepted' => 'Поле «:attribute» обязательно, когда принято «:other».',
    'required_unless' => 'Поле «:attribute» обязательно, если «:other» не равно одному из значений: :values.',
    'required_with' => 'Поле «:attribute» обязательно, когда заполнено :values.',
    'required_with_all' => 'Поле «:attribute» обязательно, когда заполнены :values.',
    'required_without' => 'Поле «:attribute» обязательно, когда не заполнено :values.',
    'required_without_all' => 'Поле «:attribute» обязательно, когда не заполнено ни одно из полей: :values.',
    'same' => 'Поля «:attribute» и «:other» должны совпадать.',
    'size' => [
        'array' => 'Количество элементов в поле «:attribute» должно быть :size.',
        'file' => 'Размер файла в поле «:attribute» должен быть :size КБ.',
        'numeric' => 'Значение поля «:attribute» должно быть :size.',
        'string' => 'Количество символов в поле «:attribute» должно быть :size.',
    ],
    'starts_with' => 'Поле «:attribute» должно начинаться с одного из этих значений: :values.',
    'string' => 'Поле «:attribute» должно быть текстом.',
    'timezone' => 'Поле «:attribute» должно содержать действительный часовой пояс.',
    'unique' => 'Такое значение поля «:attribute» уже занято.',
    'uploaded' => 'Не удалось загрузить файл в поле «:attribute».',
    'url' => 'Поле «:attribute» должно содержать действительный веб-адрес.',
    'uuid' => 'Поле «:attribute» должно содержать действительный UUID.',
    'phone' => 'Поле «:attribute» должно содержать действительный номер телефона.',

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
            'exists' => 'Это больше недоступно.',
        ],
        'parent_id' => [
            'exists' => 'Комментарий, на который вы отвечаете, больше недоступен.',
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
        'username' => 'Имя пользователя',
        'email' => 'Электронная почта',
        'password' => 'Пароль',
        'password_confirmation' => 'Подтверждение пароля',
        'cgu' => 'Условия использования',
        'title' => 'Название',
        'description' => 'Описание',
        'category_id' => 'Категория',
        'language' => 'Язык',
        'name' => 'Имя',
        'message' => 'Сообщение',
        'avatar' => 'Фото профиля',
        'banner' => 'Баннер',
        'first_name' => 'Имя',
        'last_name' => 'Фамилия',
        'city' => 'Город',
        'country' => 'Страна',
        'address' => 'Адрес',
        'phone' => 'Телефон',
        'mobile' => 'Мобильный телефон',
        'age' => 'Возраст',
        'sex' => 'Пол',
        'gender' => 'Пол',
        'day' => 'День',
        'month' => 'Месяц',
        'year' => 'Год',
        'hour' => 'Час',
        'minute' => 'Минута',
        'second' => 'Секунда',
        'content' => 'Содержимое',
        'excerpt' => 'Отрывок',
        'date' => 'Дата',
        'time' => 'Время',
        'available' => 'Доступно',
        'size' => 'Размер',
        'comment' => 'Комментарий',
        'reason' => 'Причина',
        'subject' => 'Тема',
        'body' => 'Текст письма',
        'to' => 'Получатель',
        'slug' => 'Ярлык',
        'meta_title' => 'Мета-заголовок',
        'meta_description' => 'Мета-описание',
        'sort_order' => 'Порядок сортировки',
        'price' => 'Цена',
        'duration' => 'Длительность',
        'days' => 'Количество дней',
        'ip_address' => 'IP-адрес',
        'image_url' => 'Адрес изображения',
        'link_url' => 'Ссылка',
        'starts_at' => 'Начало',
        'expires_at' => 'Окончание',
        'source' => 'Исходный адрес',
        'destination' => 'Адрес назначения',
        'icon' => 'Значок',
        'file' => 'Файл',
        'label' => 'Отображаемое название',
        'code' => 'Код',
        'website' => 'Сайт',
        'user_id' => 'Номер участника',
        'video_id' => 'Номер видео',
        'thumbnail_file' => 'Превью',
        'thumbnail' => 'Превью',
        'pages' => 'Количество страниц',
        'start_page' => 'Начальная страница',
        'pagesize' => 'Видео на странице',
        'delay' => 'Задержка',
        'updated_hours' => 'Часы после обновления',
    ],

];
