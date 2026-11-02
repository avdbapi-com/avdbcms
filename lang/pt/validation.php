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

    'accepted' => 'Você precisa aceitar o campo ":attribute".',
    'accepted_if' => 'Você precisa aceitar o campo ":attribute" quando ":other" é :value.',
    'active_url' => 'O campo ":attribute" não é um endereço web válido.',
    'after' => 'O campo ":attribute" deve ser uma data depois de :date.',
    'after_or_equal' => 'O campo ":attribute" deve ser :date ou uma data posterior.',
    'alpha' => 'O campo ":attribute" só pode ter letras.',
    'alpha_dash' => 'O campo ":attribute" só pode ter letras, números, hifens e sublinhados.',
    'alpha_num' => 'O campo ":attribute" só pode ter letras e números.',
    'array' => 'O campo ":attribute" deve ser uma lista.',
    'before' => 'O campo ":attribute" deve ser uma data antes de :date.',
    'before_or_equal' => 'O campo ":attribute" deve ser :date ou uma data anterior.',
    'between' => [
        'array' => 'O campo ":attribute" deve ter entre :min e :max itens.',
        'file' => 'O arquivo ":attribute" deve ter entre :min e :max kilobytes.',
        'numeric' => 'O campo ":attribute" deve estar entre :min e :max.',
        'string' => 'O campo ":attribute" deve ter entre :min e :max caracteres.',
    ],
    'boolean' => 'O campo ":attribute" deve ser sim ou não.',
    'confirmed' => 'O campo ":attribute" não confere com a confirmação.',
    'current_password' => 'A senha está incorreta.',
    'date' => 'O campo ":attribute" não é uma data válida.',
    'date_equals' => 'O campo ":attribute" deve ser a data :date.',
    'date_format' => 'O campo ":attribute" não segue o formato :format.',
    'declined' => 'Você precisa recusar o campo ":attribute".',
    'declined_if' => 'Você precisa recusar o campo ":attribute" quando ":other" é :value.',
    'different' => 'Os campos ":attribute" e ":other" devem ser diferentes.',
    'digits' => 'O campo ":attribute" deve ter :digits dígitos.',
    'digits_between' => 'O campo ":attribute" deve ter entre :min e :max dígitos.',
    'dimensions' => 'A imagem ":attribute" não tem um tamanho válido.',
    'distinct' => 'O campo ":attribute" tem um valor repetido.',
    'doesnt_end_with' => 'O campo ":attribute" não pode terminar com um destes valores: :values.',
    'doesnt_start_with' => 'O campo ":attribute" não pode começar com um destes valores: :values.',
    'email' => 'Digite um endereço de e-mail válido, como name@example.com.',
    'ends_with' => 'O campo ":attribute" deve terminar com um destes valores: :values.',
    'enum' => 'O valor escolhido para ":attribute" não é válido.',
    'exists' => 'O valor escolhido para ":attribute" não foi encontrado.',
    'file' => 'O campo ":attribute" deve ser um arquivo.',
    'filled' => 'O campo ":attribute" não pode ficar vazio.',
    'gt' => [
        'array' => 'O campo ":attribute" deve ter mais de :value itens.',
        'file' => 'O arquivo ":attribute" deve ter mais de :value kilobytes.',
        'numeric' => 'O campo ":attribute" deve ser maior que :value.',
        'string' => 'O campo ":attribute" deve ter mais de :value caracteres.',
    ],
    'gte' => [
        'array' => 'O campo ":attribute" deve ter :value itens ou mais.',
        'file' => 'O arquivo ":attribute" deve ter :value kilobytes ou mais.',
        'numeric' => 'O campo ":attribute" deve ser :value ou mais.',
        'string' => 'O campo ":attribute" deve ter :value caracteres ou mais.',
    ],
    'image' => 'O campo ":attribute" deve ser uma imagem.',
    'in' => 'O valor escolhido para ":attribute" não é válido.',
    'in_array' => 'O campo ":attribute" deve ser um dos valores de ":other".',
    'integer' => 'O campo ":attribute" deve ser um número inteiro.',
    'ip' => 'O campo ":attribute" deve ser um endereço IP válido.',
    'ipv4' => 'O campo ":attribute" deve ser um endereço IPv4 válido.',
    'ipv6' => 'O campo ":attribute" deve ser um endereço IPv6 válido.',
    'json' => 'O campo ":attribute" deve ser um texto JSON válido.',
    'lt' => [
        'array' => 'O campo ":attribute" deve ter menos de :value itens.',
        'file' => 'O arquivo ":attribute" deve ter menos de :value kilobytes.',
        'numeric' => 'O campo ":attribute" deve ser menor que :value.',
        'string' => 'O campo ":attribute" deve ter menos de :value caracteres.',
    ],
    'lte' => [
        'array' => 'O campo ":attribute" não pode ter mais de :value itens.',
        'file' => 'O arquivo ":attribute" não pode ter mais de :value kilobytes.',
        'numeric' => 'O campo ":attribute" deve ser :value ou menos.',
        'string' => 'O campo ":attribute" não pode ter mais de :value caracteres.',
    ],
    'mac_address' => 'O campo ":attribute" deve ser um endereço MAC válido.',
    'max' => [
        'array' => 'O campo ":attribute" não pode ter mais de :max itens.',
        'file' => 'O arquivo ":attribute" não pode ter mais de :max kilobytes.',
        'numeric' => 'O campo ":attribute" não pode ser maior que :max.',
        'string' => 'O campo ":attribute" não pode ter mais de :max caracteres.',
    ],
    'max_digits' => 'O campo ":attribute" não pode ter mais de :max dígitos.',
    'mimes' => 'O arquivo ":attribute" deve ser de um destes tipos: :values.',
    'mimetypes' => 'O arquivo ":attribute" deve ser de um destes tipos: :values.',
    'min' => [
        'array' => 'O campo ":attribute" deve ter pelo menos :min itens.',
        'file' => 'O arquivo ":attribute" deve ter pelo menos :min kilobytes.',
        'numeric' => 'O campo ":attribute" deve ser pelo menos :min.',
        'string' => 'O campo ":attribute" deve ter pelo menos :min caracteres.',
    ],
    'min_digits' => 'O campo ":attribute" deve ter pelo menos :min dígitos.',
    'multiple_of' => 'O campo ":attribute" deve ser um múltiplo de :value.',
    'not_in' => 'O valor escolhido para ":attribute" não é válido.',
    'not_regex' => 'O formato do campo ":attribute" não é válido.',
    'numeric' => 'O campo ":attribute" deve ser um número.',
    'password' => [
        'letters' => 'O campo ":attribute" deve ter pelo menos uma letra.',
        'mixed' => 'O campo ":attribute" deve ter pelo menos uma letra maiúscula e uma minúscula.',
        'numbers' => 'O campo ":attribute" deve ter pelo menos um número.',
        'symbols' => 'O campo ":attribute" deve ter pelo menos um símbolo.',
        'uncompromised' => 'O valor do campo ":attribute" apareceu em um vazamento de dados. Escolha outro.',
    ],
    'present' => 'O campo ":attribute" deve estar presente.',
    'prohibited' => 'O campo ":attribute" não é permitido.',
    'prohibited_if' => 'O campo ":attribute" não é permitido quando ":other" é :value.',
    'prohibited_unless' => 'O campo ":attribute" só é permitido quando ":other" é um destes valores: :values.',
    'prohibits' => 'O campo ":attribute" não pode ser preenchido junto com ":other".',
    'regex' => 'O formato do campo ":attribute" não é válido.',
    'required' => 'O campo ":attribute" é obrigatório.',
    'required_array_keys' => 'O campo ":attribute" deve incluir estes valores: :values.',
    'required_if' => 'O campo ":attribute" é obrigatório quando ":other" é :value.',
    'required_if_accepted' => 'O campo ":attribute" é obrigatório quando ":other" é aceito.',
    'required_unless' => 'O campo ":attribute" é obrigatório, exceto se ":other" for um destes valores: :values.',
    'required_with' => 'O campo ":attribute" é obrigatório quando :values está preenchido.',
    'required_with_all' => 'O campo ":attribute" é obrigatório quando :values estão preenchidos.',
    'required_without' => 'O campo ":attribute" é obrigatório quando :values não está preenchido.',
    'required_without_all' => 'O campo ":attribute" é obrigatório quando nenhum destes campos está preenchido: :values.',
    'same' => 'Os campos ":attribute" e ":other" devem ser iguais.',
    'size' => [
        'array' => 'O campo ":attribute" deve ter :size itens.',
        'file' => 'O arquivo ":attribute" deve ter :size kilobytes.',
        'numeric' => 'O campo ":attribute" deve ser :size.',
        'string' => 'O campo ":attribute" deve ter :size caracteres.',
    ],
    'starts_with' => 'O campo ":attribute" deve começar com um destes valores: :values.',
    'string' => 'O campo ":attribute" deve ser texto.',
    'timezone' => 'O campo ":attribute" deve ser um fuso horário válido.',
    'unique' => 'Esse valor do campo ":attribute" já está em uso.',
    'uploaded' => 'Não foi possível enviar o arquivo ":attribute".',
    'url' => 'O campo ":attribute" deve ser um endereço web válido.',
    'uuid' => 'O campo ":attribute" deve ser um UUID válido.',
    'phone' => 'O campo ":attribute" deve ser um número de telefone válido.',

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
            'exists' => 'Este conteúdo não está mais disponível.',
        ],
        'parent_id' => [
            'exists' => 'O comentário que você está respondendo não está mais disponível.',
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
        'username' => 'Nome de usuário',
        'email' => 'E-mail',
        'password' => 'Senha',
        'password_confirmation' => 'Confirmação da senha',
        'cgu' => 'Termos de serviço',
        'title' => 'Título',
        'description' => 'Descrição',
        'category_id' => 'Categoria',
        'language' => 'Idioma',
        'name' => 'Nome',
        'message' => 'Mensagem',
        'avatar' => 'Foto de perfil',
        'banner' => 'Banner',
        'first_name' => 'Nome',
        'last_name' => 'Sobrenome',
        'city' => 'Cidade',
        'country' => 'País',
        'address' => 'Endereço',
        'phone' => 'Telefone',
        'mobile' => 'Celular',
        'age' => 'Idade',
        'sex' => 'Sexo',
        'gender' => 'Gênero',
        'day' => 'Dia',
        'month' => 'Mês',
        'year' => 'Ano',
        'hour' => 'Hora',
        'minute' => 'Minuto',
        'second' => 'Segundo',
        'content' => 'Conteúdo',
        'excerpt' => 'Trecho',
        'date' => 'Data',
        'time' => 'Hora',
        'available' => 'Disponível',
        'size' => 'Tamanho',
        'comment' => 'Comentário',
        'reason' => 'Motivo',
        'subject' => 'Assunto',
        'body' => 'Corpo da mensagem',
        'to' => 'Destinatário',
        'slug' => 'Slug',
        'meta_title' => 'Meta título',
        'meta_description' => 'Meta descrição',
        'sort_order' => 'Ordem',
        'price' => 'Preço',
        'duration' => 'Duração',
        'days' => 'Número de dias',
        'ip_address' => 'Endereço IP',
        'image_url' => 'Endereço da imagem',
        'link_url' => 'Link',
        'starts_at' => 'Início',
        'expires_at' => 'Fim',
        'source' => 'Endereço de origem',
        'destination' => 'Endereço de destino',
        'icon' => 'Ícone',
        'file' => 'Arquivo',
        'label' => 'Etiqueta',
        'code' => 'Código',
        'website' => 'Site',
        'user_id' => 'Número do membro',
        'video_id' => 'Número do vídeo',
        'thumbnail_file' => 'Miniatura',
        'thumbnail' => 'Miniatura',
        'pages' => 'Número de páginas',
        'start_page' => 'Página inicial',
        'pagesize' => 'Vídeos por página',
        'delay' => 'Atraso',
        'updated_hours' => 'Horas desde a atualização',
    ],

];
