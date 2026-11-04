<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Conexión única del proceso. SIEMPRE parámetros enlazados, nunca SQL interpolado. */
function bd(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $c = config()['db'];

        // Fail-closed: sin clave configurada no se abre conexión.
        if ((string) $c['pass'] === '') {
            throw new RuntimeException('Falta ARB_A_DB_PASS: no se abre la conexión.');
        }

        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}
