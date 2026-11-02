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

    'accepted' => 'Debes aceptar el campo «:attribute».',
    'accepted_if' => 'Debes aceptar el campo «:attribute» cuando «:other» es :value.',
    'active_url' => 'El campo «:attribute» no es una dirección web válida.',
    'after' => 'El campo «:attribute» debe ser una fecha posterior a :date.',
    'after_or_equal' => 'El campo «:attribute» debe ser :date o una fecha posterior.',
    'alpha' => 'El campo «:attribute» solo puede contener letras.',
    'alpha_dash' => 'El campo «:attribute» solo puede contener letras, números, guiones y guiones bajos.',
    'alpha_num' => 'El campo «:attribute» solo puede contener letras y números.',
    'array' => 'El campo «:attribute» debe ser una lista.',
    'before' => 'El campo «:attribute» debe ser una fecha anterior a :date.',
    'before_or_equal' => 'El campo «:attribute» debe ser :date o una fecha anterior.',
    'between' => [
        'array' => 'El campo «:attribute» debe tener entre :min y :max elementos.',
        'file' => 'El archivo «:attribute» debe pesar entre :min y :max kilobytes.',
        'numeric' => 'El campo «:attribute» debe estar entre :min y :max.',
        'string' => 'El campo «:attribute» debe tener entre :min y :max caracteres.',
    ],
    'boolean' => 'El campo «:attribute» debe ser sí o no.',
    'confirmed' => 'El campo «:attribute» no coincide con su confirmación.',
    'current_password' => 'La contraseña no es correcta.',
    'date' => 'El campo «:attribute» no es una fecha válida.',
    'date_equals' => 'El campo «:attribute» debe ser la fecha :date.',
    'date_format' => 'El campo «:attribute» no sigue el formato :format.',
    'declined' => 'Debes rechazar el campo «:attribute».',
    'declined_if' => 'Debes rechazar el campo «:attribute» cuando «:other» es :value.',
    'different' => 'Los campos «:attribute» y «:other» deben ser distintos.',
    'digits' => 'El campo «:attribute» debe tener :digits dígitos.',
    'digits_between' => 'El campo «:attribute» debe tener entre :min y :max dígitos.',
    'dimensions' => 'La imagen «:attribute» no tiene un tamaño válido.',
    'distinct' => 'El campo «:attribute» tiene un valor repetido.',
    'doesnt_end_with' => 'El campo «:attribute» no puede terminar con uno de estos valores: :values.',
    'doesnt_start_with' => 'El campo «:attribute» no puede empezar con uno de estos valores: :values.',
    'email' => 'Escribe una dirección de correo válida, como name@example.com.',
    'ends_with' => 'El campo «:attribute» debe terminar con uno de estos valores: :values.',
    'enum' => 'El valor elegido para «:attribute» no es válido.',
    'exists' => 'No se encontró el valor elegido para «:attribute».',
    'file' => 'El campo «:attribute» debe ser un archivo.',
    'filled' => 'El campo «:attribute» no puede estar vacío.',
    'gt' => [
        'array' => 'El campo «:attribute» debe tener más de :value elementos.',
        'file' => 'El archivo «:attribute» debe pesar más de :value kilobytes.',
        'numeric' => 'El campo «:attribute» debe ser mayor que :value.',
        'string' => 'El campo «:attribute» debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => 'El campo «:attribute» debe tener :value elementos o más.',
        'file' => 'El archivo «:attribute» debe pesar :value kilobytes o más.',
        'numeric' => 'El campo «:attribute» debe ser :value o más.',
        'string' => 'El campo «:attribute» debe tener :value caracteres o más.',
    ],
    'image' => 'El campo «:attribute» debe ser una imagen.',
    'in' => 'El valor elegido para «:attribute» no es válido.',
    'in_array' => 'El campo «:attribute» debe ser uno de los valores de «:other».',
    'integer' => 'El campo «:attribute» debe ser un número entero.',
    'ip' => 'El campo «:attribute» debe ser una dirección IP válida.',
    'ipv4' => 'El campo «:attribute» debe ser una dirección IPv4 válida.',
    'ipv6' => 'El campo «:attribute» debe ser una dirección IPv6 válida.',
    'json' => 'El campo «:attribute» debe ser un texto JSON válido.',
    'lt' => [
        'array' => 'El campo «:attribute» debe tener menos de :value elementos.',
        'file' => 'El archivo «:attribute» debe pesar menos de :value kilobytes.',
        'numeric' => 'El campo «:attribute» debe ser menor que :value.',
        'string' => 'El campo «:attribute» debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => 'El campo «:attribute» no puede tener más de :value elementos.',
        'file' => 'El archivo «:attribute» no puede pesar más de :value kilobytes.',
        'numeric' => 'El campo «:attribute» debe ser :value o menos.',
        'string' => 'El campo «:attribute» no puede tener más de :value caracteres.',
    ],
    'mac_address' => 'El campo «:attribute» debe ser una dirección MAC válida.',
    'max' => [
        'array' => 'El campo «:attribute» no puede tener más de :max elementos.',
        'file' => 'El archivo «:attribute» no puede pesar más de :max kilobytes.',
        'numeric' => 'El campo «:attribute» no puede ser mayor que :max.',
        'string' => 'El campo «:attribute» no puede tener más de :max caracteres.',
    ],
    'max_digits' => 'El campo «:attribute» no puede tener más de :max dígitos.',
    'mimes' => 'El archivo «:attribute» debe ser de uno de estos tipos: :values.',
    'mimetypes' => 'El archivo «:attribute» debe ser de uno de estos tipos: :values.',
    'min' => [
        'array' => 'El campo «:attribute» debe tener al menos :min elementos.',
        'file' => 'El archivo «:attribute» debe pesar al menos :min kilobytes.',
        'numeric' => 'El campo «:attribute» debe ser al menos :min.',
        'string' => 'El campo «:attribute» debe tener al menos :min caracteres.',
    ],
    'min_digits' => 'El campo «:attribute» debe tener al menos :min dígitos.',
    'multiple_of' => 'El campo «:attribute» debe ser un múltiplo de :value.',
    'not_in' => 'El valor elegido para «:attribute» no es válido.',
    'not_regex' => 'El formato del campo «:attribute» no es válido.',
    'numeric' => 'El campo «:attribute» debe ser un número.',
    'password' => [
        'letters' => 'El campo «:attribute» debe contener al menos una letra.',
        'mixed' => 'El campo «:attribute» debe contener al menos una mayúscula y una minúscula.',
        'numbers' => 'El campo «:attribute» debe contener al menos un número.',
        'symbols' => 'El campo «:attribute» debe contener al menos un símbolo.',
        'uncompromised' => 'El valor del campo «:attribute» apareció en una filtración de datos. Elige otro.',
    ],
    'present' => 'El campo «:attribute» debe estar presente.',
    'prohibited' => 'El campo «:attribute» no está permitido.',
    'prohibited_if' => 'El campo «:attribute» no está permitido cuando «:other» es :value.',
    'prohibited_unless' => 'El campo «:attribute» solo está permitido cuando «:other» es uno de estos valores: :values.',
    'prohibits' => 'El campo «:attribute» no se puede rellenar junto con «:other».',
    'regex' => 'El formato del campo «:attribute» no es válido.',
    'required' => 'El campo «:attribute» es obligatorio.',
    'required_array_keys' => 'El campo «:attribute» debe incluir estos valores: :values.',
    'required_if' => 'El campo «:attribute» es obligatorio cuando «:other» es :value.',
    'required_if_accepted' => 'El campo «:attribute» es obligatorio cuando se acepta «:other».',
    'required_unless' => 'El campo «:attribute» es obligatorio salvo que «:other» sea uno de estos valores: :values.',
    'required_with' => 'El campo «:attribute» es obligatorio cuando :values está relleno.',
    'required_with_all' => 'El campo «:attribute» es obligatorio cuando :values están rellenos.',
    'required_without' => 'El campo «:attribute» es obligatorio cuando :values no está relleno.',
    'required_without_all' => 'El campo «:attribute» es obligatorio cuando ninguno de estos campos está relleno: :values.',
    'same' => 'Los campos «:attribute» y «:other» deben coincidir.',
    'size' => [
        'array' => 'El campo «:attribute» debe tener :size elementos.',
        'file' => 'El archivo «:attribute» debe pesar :size kilobytes.',
        'numeric' => 'El campo «:attribute» debe ser :size.',
        'string' => 'El campo «:attribute» debe tener :size caracteres.',
    ],
    'starts_with' => 'El campo «:attribute» debe empezar con uno de estos valores: :values.',
    'string' => 'El campo «:attribute» debe ser texto.',
    'timezone' => 'El campo «:attribute» debe ser una zona horaria válida.',
    'unique' => 'Ese valor del campo «:attribute» ya está en uso.',
    'uploaded' => 'No se pudo subir el archivo «:attribute».',
    'url' => 'El campo «:attribute» debe ser una dirección web válida.',
    'uuid' => 'El campo «:attribute» debe ser un UUID válido.',
    'phone' => 'El campo «:attribute» debe ser un número de teléfono válido.',

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
            'exists' => 'Esto ya no está disponible.',
        ],
        'parent_id' => [
            'exists' => 'El comentario al que respondes ya no está disponible.',
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
        'username' => 'Nombre de usuario',
        'email' => 'Correo electrónico',
        'password' => 'Contraseña',
        'password_confirmation' => 'Confirmación de la contraseña',
        'cgu' => 'Términos de servicio',
        'title' => 'Título',
        'description' => 'Descripción',
        'category_id' => 'Categoría',
        'language' => 'Idioma',
        'name' => 'Nombre',
        'message' => 'Mensaje',
        'avatar' => 'Foto de perfil',
        'banner' => 'Banner',
        'first_name' => 'Nombre',
        'last_name' => 'Apellidos',
        'city' => 'Ciudad',
        'country' => 'País',
        'address' => 'Dirección',
        'phone' => 'Teléfono',
        'mobile' => 'Móvil',
        'age' => 'Edad',
        'sex' => 'Sexo',
        'gender' => 'Género',
        'day' => 'Día',
        'month' => 'Mes',
        'year' => 'Año',
        'hour' => 'Hora',
        'minute' => 'Minuto',
        'second' => 'Segundo',
        'content' => 'Contenido',
        'excerpt' => 'Extracto',
        'date' => 'Fecha',
        'time' => 'Hora',
        'available' => 'Disponible',
        'size' => 'Tamaño',
        'comment' => 'Comentario',
        'reason' => 'Motivo',
        'subject' => 'Asunto',
        'body' => 'Cuerpo del mensaje',
        'to' => 'Destinatario',
        'slug' => 'Slug',
        'meta_title' => 'Metatítulo',
        'meta_description' => 'Metadescripción',
        'sort_order' => 'Orden',
        'price' => 'Precio',
        'duration' => 'Duración',
        'days' => 'Número de días',
        'ip_address' => 'Dirección IP',
        'image_url' => 'Dirección de la imagen',
        'link_url' => 'Enlace',
        'starts_at' => 'Inicio',
        'expires_at' => 'Fin',
        'source' => 'Dirección de origen',
        'destination' => 'Dirección de destino',
        'icon' => 'Icono',
        'file' => 'Archivo',
        'label' => 'Etiqueta',
        'code' => 'Código',
        'website' => 'Sitio web',
        'user_id' => 'Número de miembro',
        'video_id' => 'Número de vídeo',
        'thumbnail_file' => 'Miniatura',
        'thumbnail' => 'Miniatura',
        'pages' => 'Número de páginas',
        'start_page' => 'Página inicial',
        'pagesize' => 'Vídeos por página',
        'delay' => 'Retraso',
        'updated_hours' => 'Horas desde la actualización',
    ],

];
