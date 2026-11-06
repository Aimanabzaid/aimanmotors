<?php
/*
 * Aiman Motors — archivo PRIVADO de configuración.
 *
 * Cópialo en el servidor con el nombre «aiman_config.php» en la carpeta que está
 * AL LADO de public_html (no dentro). Así nadie puede verlo desde internet.
 * Nunca lo subas a GitHub.
 */
return [
    // Clave de 360dialog (panel de 360dialog → tu número → API key)
    'd360_api_key'      => 'PEGA_AQUI_LA_CLAVE_DE_360DIALOG',

    // Clave de Anthropic (console.anthropic.com → API Keys)
    'anthropic_api_key' => 'PEGA_AQUI_LA_CLAVE_DE_ANTHROPIC',

    // Inventa una contraseña larga: sirve para abrir aimanmotors.com/whatsapp/activar.php
    'clave_admin'       => 'INVENTA_UNA_CONTRASEÑA_LARGA',

    // Modelo de IA (rápido y económico)
    'modelo'            => 'claude-haiku-5-5',

    // Horas que el asistente se calla en un chat cuando lo pasa a Aimán o Aimán contesta desde el móvil
    'horas_pausa'       => 12,

    // false = apagar el asistente para todos
    'activo'            => true,
];
