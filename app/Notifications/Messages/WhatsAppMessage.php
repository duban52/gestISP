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

    /**
     * El documento que acompaña al mensaje: la URL PÚBLICA desde la que
     * Meta lo descarga —sus servidores son los que van a buscarlo, así
     * que no puede exigir sesión— y el nombre con el que le llega al
     * cliente.
     */
    public ?string $documentUrl = null;

    public ?string $documentName = null;

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
    /**
     * Adjunta un documento al mensaje.
     *
     * Lo que se haga con él depende de cómo se envíe, y eso lo decide
     * la pasarela: en una plantilla va como cabecera —solo si la
     * plantilla aprobada la tiene—, y en texto libre el mensaje entero
     * se manda como documento con el texto de pie.
     */
    public function document(string $url, ?string $name = null): self
    {
        $this->documentUrl = $url;
        $this->documentName = $name;

        return $this;
    }

    public function templateLanguage(string $language): self
    {
        $this->templateLanguage = $language;

        return $this;
    }
}
