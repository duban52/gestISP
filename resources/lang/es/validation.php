<?php

/*
|--------------------------------------------------------------------------
| Mensajes de validación
|--------------------------------------------------------------------------
|
| Este archivo tampoco existía: TODOS los formularios del sistema
| mostraban la clave cruda ("validation.required", "validation.email")
| en lugar de un mensaje entendible.
|
| Al final del archivo, 'attributes' traduce el nombre de cada campo
| para que el mensaje diga "El campo contraseña..." y no
| "El campo password...".
|
*/

return [

    'accepted' => 'Debe aceptar :attribute.',
    'active_url' => ':attribute no es una URL válida.',
    'after' => ':attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => ':attribute debe ser una fecha posterior o igual a :date.',
    'alpha' => ':attribute solo puede contener letras.',
    'alpha_dash' => ':attribute solo puede contener letras, números, guiones y guiones bajos.',
    'alpha_num' => ':attribute solo puede contener letras y números.',
    'array' => ':attribute debe ser una lista.',
    'before' => ':attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => ':attribute debe ser una fecha anterior o igual a :date.',
    'between' => [
        'numeric' => ':attribute debe estar entre :min y :max.',
        'file' => ':attribute debe pesar entre :min y :max kilobytes.',
        'string' => ':attribute debe tener entre :min y :max caracteres.',
        'array' => ':attribute debe tener entre :min y :max elementos.',
    ],
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación de :attribute no coincide. Escríbala de nuevo.',
    'current_password' => 'La contraseña actual no es correcta.',
    'date' => ':attribute no es una fecha válida.',
    'date_equals' => ':attribute debe ser una fecha igual a :date.',
    'date_format' => ':attribute no corresponde al formato :format.',
    'declined' => 'Debe rechazar :attribute.',
    'different' => ':attribute y :other deben ser diferentes.',
    'digits' => ':attribute debe tener :digits dígitos.',
    'digits_between' => ':attribute debe tener entre :min y :max dígitos.',
    'dimensions' => ':attribute tiene dimensiones de imagen no válidas.',
    'distinct' => 'El campo :attribute tiene un valor repetido.',
    'email' => 'Escriba un correo electrónico válido (por ejemplo: nombre@dominio.com).',
    'ends_with' => ':attribute debe terminar en: :values.',
    'exists' => 'La opción seleccionada en :attribute no es válida.',
    'file' => ':attribute debe ser un archivo.',
    'filled' => 'El campo :attribute no puede estar vacío.',
    'gt' => [
        'numeric' => ':attribute debe ser mayor que :value.',
        'file' => ':attribute debe pesar más de :value kilobytes.',
        'string' => ':attribute debe tener más de :value caracteres.',
        'array' => ':attribute debe tener más de :value elementos.',
    ],
    'gte' => [
        'numeric' => ':attribute debe ser como mínimo :value.',
        'file' => ':attribute debe pesar como mínimo :value kilobytes.',
        'string' => ':attribute debe tener como mínimo :value caracteres.',
        'array' => ':attribute debe tener como mínimo :value elementos.',
    ],
    'image' => ':attribute debe ser una imagen (JPG, PNG, GIF o WEBP).',
    'in' => 'La opción seleccionada en :attribute no es válida.',
    'in_array' => 'El campo :attribute no existe en :other.',
    'integer' => ':attribute debe ser un número entero.',
    'ip' => ':attribute debe ser una dirección IP válida.',
    'ipv4' => ':attribute debe ser una dirección IPv4 válida.',
    'ipv6' => ':attribute debe ser una dirección IPv6 válida.',
    'json' => ':attribute debe ser una cadena JSON válida.',
    'lt' => [
        'numeric' => ':attribute debe ser menor que :value.',
        'file' => ':attribute debe pesar menos de :value kilobytes.',
        'string' => ':attribute debe tener menos de :value caracteres.',
        'array' => ':attribute debe tener menos de :value elementos.',
    ],
    'lte' => [
        'numeric' => ':attribute debe ser como máximo :value.',
        'file' => ':attribute debe pesar como máximo :value kilobytes.',
        'string' => ':attribute debe tener como máximo :value caracteres.',
        'array' => ':attribute debe tener como máximo :value elementos.',
    ],
    'max' => [
        'numeric' => ':attribute no puede ser mayor que :max.',
        'file' => ':attribute no puede pesar más de :max kilobytes.',
        'string' => ':attribute no puede tener más de :max caracteres.',
        'array' => ':attribute no puede tener más de :max elementos.',
    ],
    'mimes' => ':attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => ':attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'numeric' => ':attribute debe ser al menos :min.',
        'file' => ':attribute debe pesar al menos :min kilobytes.',
        'string' => ':attribute debe tener al menos :min caracteres.',
        'array' => ':attribute debe tener al menos :min elementos.',
    ],
    'multiple_of' => ':attribute debe ser múltiplo de :value.',
    'not_in' => 'La opción seleccionada en :attribute no es válida.',
    'not_regex' => 'El formato de :attribute no es válido.',
    'numeric' => ':attribute debe ser un número.',
    'password' => 'La contraseña no es correcta.',
    'present' => 'El campo :attribute debe estar presente.',
    'prohibited' => 'El campo :attribute está prohibido.',
    'prohibited_if' => 'El campo :attribute está prohibido cuando :other es :value.',
    'prohibited_unless' => 'El campo :attribute está prohibido a menos que :other esté en :values.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => 'Falta completar :attribute.',
    'required_array_keys' => 'El campo :attribute debe contener entradas para: :values.',
    'required_if' => 'Debe completar :attribute cuando :other es :value.',
    'required_unless' => 'Debe completar :attribute a menos que :other esté en :values.',
    'required_with' => 'Debe completar :attribute cuando :values está presente.',
    'required_with_all' => 'Debe completar :attribute cuando :values están presentes.',
    'required_without' => 'Debe completar :attribute cuando :values no está presente.',
    'required_without_all' => 'Debe completar :attribute cuando ninguno de :values está presente.',
    'same' => ':attribute y :other deben coincidir.',
    'size' => [
        'numeric' => ':attribute debe ser :size.',
        'file' => ':attribute debe pesar :size kilobytes.',
        'string' => ':attribute debe tener :size caracteres.',
        'array' => ':attribute debe contener :size elementos.',
    ],
    'starts_with' => ':attribute debe comenzar con: :values.',
    'string' => ':attribute debe ser texto.',
    'timezone' => ':attribute debe ser una zona horaria válida.',
    'unique' => 'Ese valor de :attribute ya está registrado. Use uno diferente.',
    'uploaded' => 'No se pudo subir :attribute. Verifique el tamaño del archivo e intente de nuevo.',
    'url' => ':attribute debe ser una URL válida.',
    'uuid' => ':attribute debe ser un UUID válido.',

    /*
    |----------------------------------------------------------------
    | Mensajes por campo
    |----------------------------------------------------------------
    |
    | Textos a la medida para los campos donde un mensaje genérico no
    | orienta lo suficiente.
    |
    */

    'custom' => [
        'email' => [
            'required' => 'Escriba su correo electrónico.',
        ],
        'password' => [
            'required' => 'Escriba su contraseña.',
            'min' => 'La contraseña debe tener al menos :min caracteres.',
            'confirmed' => 'Las contraseñas no coinciden. Escríbalas de nuevo.',
        ],
        'branch_id' => [
            'required' => 'Seleccione la sucursal en la que va a trabajar.',
        ],
    ],

    /*
    |----------------------------------------------------------------
    | Nombres de los campos
    |----------------------------------------------------------------
    |
    | Para que el mensaje diga "Falta completar la contraseña" y no
    | "Falta completar password".
    |
    */

    'attributes' => [
        'name' => 'el nombre',
        'last_name' => 'el apellido',
        'email' => 'el correo electrónico',
        'password' => 'la contraseña',
        'password_confirmation' => 'la confirmación de la contraseña',
        'current_password' => 'la contraseña actual',
        'identity_number' => 'el número de identificación',
        'number_phone' => 'el teléfono',
        'aditional_phone' => 'el teléfono adicional',
        'address' => 'la dirección',
        'neighborhood' => 'el barrio',
        'department' => 'el departamento',
        'municipality' => 'el municipio',
        'branch_id' => 'la sucursal',
        'client_id' => 'el cliente',
        'contract_id' => 'el contrato',
        'plan_id' => 'el plan',
        'role_id' => 'el rol',
        'user_id' => 'el usuario',
        'material_id' => 'el material',
        'quantity' => 'la cantidad',
        'amount' => 'el valor',
        'description' => 'la descripción',
        'body' => 'el comentario',
        'status' => 'el estado',
        'observations_technical' => 'el comentario del técnico',
        'client_observation' => 'el comentario del cliente',
        'solution' => 'la solución aplicada',
        'client_signature' => 'la firma del cliente',
        'serial_number' => 'el número de serie',
        'cpe_sn' => 'el serial del equipo',
        'user_pppoe' => 'el usuario PPPoE',
        'password_pppoe' => 'la contraseña PPPoE',
        'ssid_wifi' => 'el nombre de la red WiFi',
        'password_wifi' => 'la contraseña del WiFi',
        'installments_total' => 'el número de cuotas',
        'verification_comment' => 'el comentario de verificación',
        'reason' => 'el motivo',
        'images' => 'las imágenes',
    ],

];
