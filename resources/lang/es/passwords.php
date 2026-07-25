<?php

/*
|--------------------------------------------------------------------------
| Mensajes del restablecimiento de contraseña
|--------------------------------------------------------------------------
|
| Este archivo no existía: el usuario veía "passwords.user" o
| "passwords.token" en pantalla. Cada mensaje explica ahora el motivo
| y el siguiente paso.
|
*/

return [

    'reset' => '¡Listo! Su contraseña se actualizó correctamente.',

    'sent' => 'Le enviamos un enlace a su correo para crear una contraseña nueva. Revise su bandeja de entrada y, si no lo encuentra, la carpeta de correo no deseado. El enlace vence en 60 minutos.',

    'throttled' => 'Ya solicitó un enlace hace poco. Espere unos minutos antes de pedir otro.',

    'token' => 'El enlace para restablecer la contraseña no es válido o ya venció. Solicite uno nuevo desde "¿Olvidaste tu contraseña?".',

    'user' => 'No encontramos ninguna cuenta con ese correo electrónico. Verifique que esté bien escrito.',

];
