<?php
declare(strict_types=1);

require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/errores.php';

/**
 * Límite de cargas por IP: ventana móvil de una hora sobre `limite_carga`.
 *
 * Se revisa dos veces: una al principio (barato, evita trabajo inútil) y otra dentro de
 * la transacción del guardado, que es la que manda. La segunda toma un lock por IP, así que
 * dos peticiones simultáneas no pueden colarse contando a la vez.
 */

/**
 * Cuenta lo consumido en la última hora y cuánto falta para que se libere un hueco.
 *
 * @return array{total: int, espera: int}|null null si no hay cuota que aplicar
 */
function cuotaDeCarga(?string $ip): ?array
{
    $maximo = (int) (config()['api']['max_cargas_hora'] ?? 0);
    // Sin IP no hay cubeta propia: contar a ciegas castigaría a todos los clientes juntos.
    if ($maximo <= 0 || $ip === null) {
        return null;
    }

    $stmt = bd()->prepare(
        "select count(*)::int as total,
                coalesce(extract(epoch from (min(creado_en) + interval '1 hour' - now()))::int, 0) as espera
           from limite_carga
          where ip = cast(:ip as inet)
            and creado_en > now() - interval '1 hour'"
    );
    $stmt->execute(['ip' => $ip]);
    $fila = $stmt->fetch();

    return [
        'total' => $fila === false ? 0 : (int) $fila['total'],
        'espera' => $fila === false ? 0 : max(1, (int) $fila['espera']),
    ];
}

/** Comprobación temprana: si ya está agotada, no se gasta tiempo en leer el archivo. */
function verificarLimiteCarga(?string $ip): void
{
    $cuota = cuotaDeCarga($ip);
    if ($cuota === null || $cuota['total'] < (int) (config()['api']['max_cargas_hora'] ?? 0)) {
        return;
    }

    throw new ErrorDeCarga(
        'limite_cargas',
        sprintf('%d por hora; reintente en %d segundos', (int) config()['api']['max_cargas_hora'], $cuota['espera']),
        ['reintentar_en' => $cuota['espera']]
    );
}

/**
 * Reserva la cuota de esta carga. Debe llamarse DENTRO de la transacción que guarda el
 * archivo: si el guardado falla, el rollback devuelve la cuota.
 */
function registrarCarga(?string $ip): void
{
    if (cuotaDeCarga($ip) === null) {
        return;
    }

    $maximo = (int) (config()['api']['max_cargas_hora'] ?? 0);

    // Lock por IP dentro de la transacción: cierra la carrera entre contar e insertar.
    bd()->prepare('select pg_advisory_xact_lock(hashtextextended(cast(:ip as text), 0))')
        ->execute(['ip' => $ip]);

    $cuota = cuotaDeCarga($ip);
    if ($cuota !== null && $cuota['total'] >= $maximo) {
        throw new ErrorDeCarga(
            'limite_cargas',
            sprintf('%d por hora; reintente en %d segundos', $maximo, $cuota['espera']),
            ['reintentar_en' => $cuota['espera']]
        );
    }

    bd()->prepare('insert into limite_carga (ip) values (cast(:ip as inet))')
        ->execute(['ip' => $ip]);
}
