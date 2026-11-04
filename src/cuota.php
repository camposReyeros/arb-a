<?php
declare(strict_types=1);

require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/errores.php';

/**
 * Cuota global de disco: la suma de los .gz vivos no puede pasar de `archivos.cuota_bytes`.
 *
 * Un archivo cuenta mientras esté vivo (ni vencido ni borrado). Al vencer, la purga lo borra
 * y el espacio se libera solo: no hay contador que mantener ni que descuadrar.
 */

function cuotaDeDisco(): int
{
    return (int) (config()['archivos']['cuota_bytes'] ?? 0);
}

/** Bytes comprimidos que hoy ocupan los archivos vivos. */
function usoDeDisco(): int
{
    $fila = bd()->query(
        'select coalesce(sum(bytes_gz), 0)::bigint as total
           from archivo
          where borrado_en is null and expira_en > now()'
    )->fetch();

    return $fila === false ? 0 : (int) $fila['total'];
}

/** Chequeo temprano: si el disco está lleno, no se gasta tiempo comprimiendo. */
function verificarCuota(): void
{
    $cuota = cuotaDeDisco();
    if ($cuota <= 0) {
        return;
    }

    if (usoDeDisco() >= $cuota) {
        throw new ErrorDeCarga('cuota_excedida', 'el almacenamiento está lleno');
    }
}

/**
 * Revisa que el archivo recién comprimido quepa. Debe llamarse DENTRO de la transacción que
 * lo guarda: el lock global impide que dos subidas simultáneas se pasen del tope, y el
 * rollback deja todo como estaba si no cabe.
 */
function reservarCuota(int $bytesGz): void
{
    $cuota = cuotaDeDisco();
    if ($cuota <= 0) {
        return;
    }

    bd()->prepare("select pg_advisory_xact_lock(hashtextextended('arb-a:cuota', 0))")->execute();

    $uso = usoDeDisco();
    if ($uso + $bytesGz > $cuota) {
        $libres = max(0, $cuota - $uso);
        throw new ErrorDeCarga(
            'cuota_excedida',
            sprintf('quedan %d MB de %d MB', intdiv($libres, 1024 ** 2), intdiv($cuota, 1024 ** 2))
        );
    }
}
