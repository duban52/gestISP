<?php

/*
|--------------------------------------------------------------------------
| Mensajes de autenticación
|--------------------------------------------------------------------------
|
| Sin este archivo Laravel mostraba la clave cruda ("auth.failed") en
| lugar de un mensaje, así que el usuario no entendía qué pasaba.
|
| Criterio de redacción: decir QUÉ ocurrió y QUÉ hacer a continuación,
| sin tecnicismos.
|
*/

return [

    'failed' => 'El correo o la contraseña no son correctos. Revise sus datos e intente de nuevo.',

    'password' => 'La contraseña que ingresó no es correcta.',

    'throttle' => 'Demasiados intentos fallidos. Por seguridad, espere :seconds segundos antes de volver a intentarlo.',

    'unauthorized' => 'Esta acción no está autorizada.',

    /*
    |----------------------------------------------------------------
    | Mensajes propios de GestISP
    |----------------------------------------------------------------
    |
    | Situaciones de este sistema: la sesión necesita una sucursal
    | activa, y el usuario puede estar inhabilitado o ser expulsado.
    |
    */

    'inactive' => 'Su usuario está inhabilitado y no puede iniciar sesión. Comuníquese con un administrador de GestISP.',

    'branch_required' => 'Seleccione la sucursal en la que va a trabajar para continuar.',

    'branch_forbidden' => 'Su usuario no tiene acceso a la sucursal seleccionada. Elija otra o solicite el acceso a un administrador.',

    'branch_missing' => 'Su sesión no tiene una sucursal activa. Vuelva a iniciar sesión para continuar.',

    'session_expired' => 'Cerramos su sesión automáticamente por :minutes minutos de inactividad. Vuelva a iniciar sesión para continuar.',

    'session_closed_by_admin' => 'Un administrador cerró su sesión. Si necesita seguir trabajando, inicie sesión de nuevo.',

    'session_user_disabled' => 'Su usuario fue inhabilitado mientras trabajaba, por eso se cerró la sesión. Comuníquese con un administrador.',

    'session_inactive_json' => 'Su sesión no está activa. Actualice la página e inicie sesión de nuevo.',

];
