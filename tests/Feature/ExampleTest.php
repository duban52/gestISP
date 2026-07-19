<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * La raíz es el dashboard protegido: un visitante sin sesión
     * debe ser redirigido al login.
     */
    public function test_la_raiz_redirige_al_login_para_visitantes(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
