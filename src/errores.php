<?php
declare(strict_types=1);

/**
 * Catálogo de errores de la API de carga.
 *
 * `codigo` es el contrato con el cliente (la app en Python compara `codigo`, nunca el
 * mensaje). Cada entrada fija el status HTTP y el texto por omisión; las excepciones
 * pueden añadir un detalle entre paréntesis y datos extra en el JSON.
 */

const ARB_A_ERRORES = [
    'metodo_no_permitido'         => [405, 'método no permitido; use PUT o POST'],
    'servicio_no_disponible'      => [503, 'el servicio de carga no está disponible'],
    'apikey_invalida'             => [401, 'API-Key inválida o ausente'],
    'destinatarios_invalidos'     => [422, 'X-Correos no trae destinatarios válidos'],
    'falta_archivo'               => [422, 'no llegó el CSV en el cuerpo de la petición'],
    'archivo_muy_grande'          => [413, 'el archivo excede el tamaño máximo permitido'],
    'archivo_vacio'               => [422, 'el archivo está vacío'],
    'archivo_incompleto'          => [422, 'el archivo se leyó incompleto; reintente la carga'],
    'limite_cargas'               => [429, 'demasiadas cargas en la última hora'],
    'cuota_excedida'              => [507, 'no hay espacio disponible para más archivos'],
    'destinatario_no_permitido'   => [422, 'X-Correos trae un dominio no autorizado'],
    'csv_ya_comprimido'           => [422, 'el archivo ya viene comprimido (gzip); envíe el CSV sin comprimir'],
    'csv_binario'                 => [422, 'el contenido no parece texto CSV'],
    'csv_vacio'                   => [422, 'el CSV no trae datos'],
    'csv_ilegible'                => [422, 'no se pudo leer el archivo'],
    'csv_columnas_inconsistentes' => [422, 'todas las líneas deben tener el mismo número de columnas'],
    'csv_demasiadas_columnas'     => [422, 'el CSV tiene demasiadas columnas'],
    'error_interno'               => [500, 'no se pudo guardar el archivo'],
];

/** Error de negocio de la carga: lleva su código estable y su status HTTP. */
final class ErrorDeCarga extends InvalidArgumentException
{
    public readonly int $estado;

    /** @param array<string, mixed> $extra datos adicionales para el JSON de respuesta */
    public function __construct(
        public readonly string $codigo,
        ?string $detalle = null,
        public readonly array $extra = []
    ) {
        [$estado, $mensaje] = ARB_A_ERRORES[$codigo] ?? [422, 'solicitud inválida'];
        $this->estado = $estado;
        parent::__construct($detalle === null ? $mensaje : $mensaje . ' (' . $detalle . ')');
    }
}
