<?php
declare(strict_types=1);

/**
 * Copiar a config.php (ignorado por git) y ajustar.
 * Las credenciales salen de variables de entorno; aquí solo viven los defaults.
 */
return [
    'db' => [
        'dsn' => getenv('ARB_A_DSN') ?: 'pgsql:host=db-arb-a;port=5432;dbname=arb_a',
        'user' => getenv('ARB_A_DB_USER') ?: 'arb_a',
        'pass' => getenv('ARB_A_DB_PASS') ?: '',
    ],
    'api' => [
        // Clave de la API de carga. Vive en el .env del servidor, nunca en git.
        'key' => getenv('ARB_A_APIKEY') ?: '',
        // Tope del CSV sin comprimir (30 MB reales; margen a 64 MB).
        'max_bytes' => (int) (getenv('ARB_A_MAX_BYTES') ?: 64 * 1024 * 1024),
        // Cargas aceptadas por IP en una hora (ventana móvil). 0 = sin límite.
        'max_cargas_hora' => (int) (getenv('ARB_A_MAX_CARGAS_HORA') ?: 100),
    ],
    'archivos' => [
        // Fuera del docroot: nunca se sirven por HTTP directo.
        'dir' => getenv('ARB_A_DIR') ?: '/var/lib/arb-a/archivos',
        // Vigencia del archivo y de su link.
        'dias' => (int) (getenv('ARB_A_DIAS') ?: 90),
    ],
    'correo' => [
        'host' => getenv('ARB_A_SMTP_HOST') ?: 'smtp.servicios.care',
        'puerto' => (int) (getenv('ARB_A_SMTP_PORT') ?: 587),
        'usuario' => getenv('ARB_A_SMTP_USER') ?: 'noreply-arb-a@servicios.care',
        'clave' => getenv('ARB_A_SMTP_PASS') ?: '',
        'de' => getenv('ARB_A_SMTP_FROM') ?: 'noreply-arb-a@servicios.care',
        'nombre_de' => 'ARB-A',
        'timeout' => (int) (getenv('ARB_A_SMTP_TIMEOUT') ?: 15),
    ],
    'url_base' => getenv('ARB_A_URL_BASE') ?: 'https://arb-a.servicios.care',
    // IPs del proxy de confianza (Caddy) que pueden mandar X-Forwarded-For/Proto.
    'proxy_confiable' => array_values(array_filter(array_map(
        'trim',
        explode(',', getenv('ARB_A_PROXY_IPS') ?: '')
    ))),
];
