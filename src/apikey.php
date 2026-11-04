<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/usuarios.php';

/**
 * API-Key única del servicio de carga.
 *
 * Se guarda solo su sha256 en la tabla `apikey` (una sola fila). Mientras no exista fila se
 * usa la del entorno (`ARB_A_APIKEY`), para no cerrar la API en el primer despliegue. Al
 * regenerarla desde el panel manda la de la base y el entorno deja de usarse.
 */

/**
 * Lee la clave vigente en una sola consulta.
 *
 * Si la tabla aún no existe (esquema anterior a la migración) cae al entorno; cualquier otro
 * fallo de BD devuelve `origen = 'error'` para fallar cerrado en vez de resucitar la clave vieja.
 *
 * @return array{origen:string, hash:?string, creada_en:?string, creada_por:?string}
 */
function apikeyFuente(): array
{
    $vacio = ['origen' => 'ninguna', 'hash' => null, 'creada_en' => null, 'creada_por' => null];

    try {
        $fila = bd()->query('select hash, creada_en, creada_por from apikey where id = 1')->fetch();
    } catch (PDOException $e) {
        if ($e->getCode() !== '42P01') {
            return ['origen' => 'error'] + $vacio;
        }
        // Tabla ausente (42P01): despliegue todavía sin la migración.
        $fila = false;
    }

    if (is_array($fila) && (string) $fila['hash'] !== '') {
        return [
            'origen' => 'bd',
            'hash' => (string) $fila['hash'],
            'creada_en' => $fila['creada_en'] !== null ? (string) $fila['creada_en'] : null,
            'creada_por' => $fila['creada_por'] !== null ? (string) $fila['creada_por'] : null,
        ];
    }

    $entorno = (string) (config()['api']['key'] ?? '');

    return [
        'origen' => $entorno !== '' ? 'entorno' : 'ninguna',
        'hash' => $entorno !== '' ? hash('sha256', $entorno) : null,
        'creada_en' => null,
        'creada_por' => null,
    ];
}

/** @return array{origen:string, creada_en:?string, creada_por:?string} */
function apikeyInfo(): array
{
    $fuente = apikeyFuente();
    unset($fuente['hash']);

    return $fuente;
}

/**
 * Estado de la clave recibida: `ok`, `invalida`, `sin_configurar` o `error`.
 * Compara siempre sha256 en tiempo constante; una clave vacía nunca es válida.
 */
function apikeyComprobar(string $recibida): string
{
    $fuente = apikeyFuente();

    if ($fuente['origen'] === 'error') {
        return 'error';
    }
    if ($fuente['origen'] === 'ninguna' || $fuente['hash'] === null) {
        return 'sin_configurar';
    }
    if ($recibida === '') {
        return 'invalida';
    }

    return hash_equals($fuente['hash'], hash('sha256', $recibida)) ? 'ok' : 'invalida';
}

function apikeyValida(string $recibida): bool
{
    return apikeyComprobar($recibida) === 'ok';
}

/**
 * Genera y guarda una clave nueva. Devuelve la única copia en claro que existirá: no se
 * guarda en la base ni se puede recuperar después.
 */
function regenerarApikey(?int $usuarioId, string $quien): string
{
    $clave = bin2hex(random_bytes(32));
    $bd = bd();
    $bd->beginTransaction();

    try {
        $bd->prepare(
            'insert into apikey (id, hash, creada_en, creada_por) values (1, :h, now(), :p)
             on conflict (id) do update set hash = excluded.hash,
                                            creada_en = excluded.creada_en,
                                            creada_por = excluded.creada_por'
        )->execute(['h' => hash('sha256', $clave), 'p' => $quien]);

        bitacora($usuarioId, 'apikey_regenerada', ['por' => $quien]);
        $bd->commit();
    } catch (Throwable $e) {
        $bd->rollBack();
        throw $e;
    }

    return $clave;
}
