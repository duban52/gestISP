<?php

namespace App\Notifications\Messages;

/**
 * Mensaje de WhatsApp que produce una notificación.
 *
 * Guarda el texto del mensaje y, opcionalmente, los datos de la
 * plantilla aprobada por Meta. El gateway decide cómo enviarlo según
 * el proveedor: como texto libre o como plantilla.
 *
 * Sobre las PLANTILLAS de Meta: para iniciar una conversación con un
 * cliente (fuera de la ventana de 24 h) Meta obliga a usar una
 * plantilla previamente aprobada. Una plantilla tiene un nombre y
 * unas variables ({{1}}, {{2}}...) que se rellenan con "params". El
 * "body" en texto se usa con el driver simulado y como respaldo.
 */
class WhatsAppMessage
{
    /** @var array<int, string> */
    public array $templateParams = [];

    /**
     * Idioma puntual de la plantilla en formato de Meta (por ejemplo,
     * "es_CO" o "en_US"). Si no se define, se usa la configuración
     * general del sistema.
     */
    public ?string $templateLanguage = null;

    public function __construct(
        public string $body = '',
        public ?string $templateName = null,
    ) {
    }

    public static function make(string $body = ''): self
    {
        return new self($body);
    }

    /**
     * Texto del mensaje (para driver simulado y para ventana abierta).
     */
    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Plantilla aprobada de Meta y sus variables, en orden.
     *
     * @param  array<int, string>  $params
     */
    public function template(string $name, array $params = []): self
    {
        $this->templateName = $name;
        $this->templateParams = array_values($params);

        return $this;
    }

    /**
     * Define el idioma de esta plantilla sin afectar el de las
     * notificaciones operativas restantes.
     */
    public function templateLanguage(string $language): self
    {
        $this->templateLanguage = $language;

        return $this;
    }
}
