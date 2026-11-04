<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/../vistas/correo_aviso.php';

/**
 * Envío SMTP sin dependencias externas.
 * 465 = TLS directo; cualquier otro puerto = STARTTLS obligatorio.
 *
 * La conexión es reutilizable a propósito: una subida avisa hasta a 50 destinatarios y
 * abrir TCP+TLS+AUTH por cada uno es lo que provocaba timeouts.
 */
final class ClienteSmtp
{
    /** @var resource|null */
    private $fp = null;

    public function __construct(private array $cfg)
    {
    }

    public function conectado(): bool
    {
        return is_resource($this->fp);
    }

    /** Abre la sesión y deja listo el sobre: EHLO, TLS y AUTH. */
    public function conectar(): void
    {
        $this->soltar();

        $esquema = (int) $this->cfg['puerto'] === 465 ? 'ssl://' : 'tcp://';
        $remoto = $esquema . $this->cfg['host'] . ':' . (int) $this->cfg['puerto'];
        $timeout = (int) $this->cfg['timeout'];

        $contexto = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]]);

        $fp = @stream_socket_client($remoto, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $contexto);
        if ($fp === false) {
            throw new RuntimeException('conexión SMTP: ' . ($errstr !== '' ? $errstr : 'sin detalle') . ' (' . $errno . ')');
        }

        $this->fp = $fp;
        stream_set_timeout($fp, $timeout);
        $this->leer([220]);

        $this->comando('EHLO ' . $this->hostname(), [250]);

        if ((int) $this->cfg['puerto'] !== 465) {
            $this->comando('STARTTLS', [220]);
            $cifrado = stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cifrado !== true) {
                throw new RuntimeException('no se pudo negociar TLS');
            }
            $this->comando('EHLO ' . $this->hostname(), [250]);
        }

        $usuario = (string) $this->cfg['usuario'];
        if ($usuario !== '') {
            $this->comando('AUTH LOGIN', [334]);
            $this->comando(base64_encode($usuario), [334]);
            $this->comando(base64_encode((string) $this->cfg['clave']), [235]);
        }
    }

    /** Entrega un mensaje en la sesión abierta. El 250 de DATA es la entrega. */
    public function enviar(string $para, string $mensaje): void
    {
        if (!is_resource($this->fp)) {
            throw new RuntimeException('SMTP sin conexión');
        }

        $this->comando('MAIL FROM:<' . sinCrlf((string) $this->cfg['de']) . '>', [250]);
        $this->comando('RCPT TO:<' . sinCrlf($para) . '>', [250, 251]);
        $this->comando('DATA', [354]);
        $this->escribir($this->escaparPuntos($mensaje) . "\r\n.");
        $this->leer([250]);
    }

    /** Deja la sesión limpia tras un fallo; si no se puede, se cierra y se reconecta luego. */
    public function reiniciar(): void
    {
        if (!is_resource($this->fp)) {
            return;
        }

        try {
            $this->comando('RSET', [250]);
        } catch (Throwable) {
            $this->soltar();
        }
    }

    /** Cierre cortés. Un QUIT fallido no cambia el hecho de que los mensajes ya salieron. */
    public function cerrar(): void
    {
        if (is_resource($this->fp)) {
            try {
                $this->comando('QUIT', [221]);
            } catch (Throwable) {
                // best-effort: el servidor cerró o no contestó
            }
        }

        $this->soltar();
    }

    private function soltar(): void
    {
        if (is_resource($this->fp)) {
            @fclose($this->fp);
        }

        $this->fp = null;
    }

    private function comando(string $linea, array $esperados): string
    {
        $this->escribir($linea);

        return $this->leer($esperados);
    }

    private function escribir(string $datos): void
    {
        if (!is_resource($this->fp)) {
            throw new RuntimeException('SMTP sin conexión');
        }

        $datos = $datos . "\r\n";
        if (@fwrite($this->fp, $datos) !== strlen($datos)) {
            throw new RuntimeException('no se pudo escribir al servidor SMTP');
        }
    }

    private function leer(array $esperados): string
    {
        if (!is_resource($this->fp)) {
            throw new RuntimeException('SMTP sin conexión');
        }

        $texto = '';
        while (true) {
            $linea = fgets($this->fp);
            if ($linea === false) {
                throw new RuntimeException('el servidor SMTP cerró la conexión');
            }
            $texto .= $linea;
            $codigo = (int) substr($linea, 0, 3);

            if (strlen($linea) < 4 || $linea[3] !== '-') {
                if (!in_array($codigo, $esperados, true)) {
                    throw new RuntimeException('SMTP ' . $codigo . ': ' . trim(substr($linea, 4)));
                }

                return $texto;
            }
        }
    }

    private function escaparPuntos(string $mensaje): string
    {
        return preg_replace('/^\./m', '..', $mensaje) ?? $mensaje;
    }

    private function hostname(): string
    {
        $nombre = gethostname();

        return $nombre !== false && $nombre !== '' ? $nombre : 'arb-a.local';
    }
}

/** Quita CR/LF de cualquier valor que termine en una cabecera (defensa en el sumidero). */
function sinCrlf(string $valor): string
{
    return str_replace(["\r", "\n", "\0"], '', $valor);
}

/**
 * Avisa a cada destinatario y deja el resultado en `envio` (salud del panel).
 * Una sola sesión SMTP para toda la tanda; se reconecta solo si se cae.
 */
function avisarDestinatarios(int $archivoId, array $destinatarios, string $nombre, int $bytes, string $tipo, string $link, string $expiraLink, string $expira): array
{
    $cfg = config()['correo'];
    [$texto, $html] = cuerpoAviso($nombre, $link, $expiraLink, $expira, $bytes, $tipo);
    $cliente = new ClienteSmtp($cfg);
    $ok = 0;
    $conError = 0;
    $caidas = 0;

    foreach ($destinatarios as $para) {
        $estado = 'error';
        $detalle = null;

        try {
            if (!$cliente->conectado()) {
                if ($caidas >= 2) {
                    throw new RuntimeException('SMTP no disponible; se dejó de reintentar');
                }
                $cliente->conectar();
                $caidas = 0;
            }

            $cliente->enviar($para, mensajeMime($cfg, $para, $nombre, $texto, $html));
            $estado = 'ok';
            $ok++;
        } catch (Throwable $e) {
            $detalle = mb_substr($e->getMessage(), 0, 500);
            $conError++;
            if (!$cliente->conectado()) {
                $caidas++;
            }
            $cliente->reiniciar();
            // Sin el correo en claro en el log: PII fuera del log del servidor.
            error_log('arb-a smtp archivo ' . $archivoId . ' destino ' . substr(hash('sha256', $para), 0, 12) . ': ' . $detalle);
        }

        try {
            bd()->prepare('insert into envio (archivo_id, correo, estado, error) values (:a, :c, :e, :x)')
                ->execute(['a' => $archivoId, 'c' => $para, 'e' => $estado, 'x' => $detalle]);
        } catch (Throwable $e) {
            error_log('arb-a envio no registrado (archivo ' . $archivoId . '): ' . $e->getMessage());
        }
    }

    $cliente->cerrar();

    return ['ok' => $ok, 'error' => $conError];
}

function cuerpoAviso(string $nombre, string $link, string $expiraLink, string $expira, int $bytes, string $tipo = 'csv'): array
{
    $fechaLink = fechaCorta($expiraLink);
    $fechaArchivo = fechaCorta($expira);
    $texto = "Hola:\n\n"
        . "El archivo {$nombre} (" . avisoTamano($bytes) . ") ya está disponible en ARB-A.\n\n"
        . "Descárguelo aquí: {$link}\n\n"
        . "Este enlace estará vigente hasta el {$fechaLink}.\n"
        . "Después podrá descargarlo desde el portal (" . config()['url_base'] . ") "
        . "con su usuario de siempre, hasta el {$fechaArchivo}.\n\n"
        . "ARB-A · servicios.care\n";

    return [$texto, avisoHtml($nombre, $link, $expiraLink, $expira, $bytes, $tipo)];
}

function mensajeMime(array $cfg, string $para, string $nombre, string $texto, string $html): string
{
    $frontera = '=arba-' . bin2hex(random_bytes(8));
    $dominio = (string) parse_url((string) config()['url_base'], PHP_URL_HOST);
    $asunto = 'ARB-A: ' . sinCrlf($nombre) . ' disponible';

    $cabeceras = [
        'Date: ' . date('r'),
        'From: "ARB-A" <' . sinCrlf((string) $cfg['de']) . '>',
        'To: <' . sinCrlf($para) . '>',
        'Subject: ' . mb_encode_mimeheader($asunto, 'UTF-8', 'B', "\r\n"),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($dominio !== '' ? $dominio : 'arb-a.local') . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $frontera . '"',
    ];

    $partes = [
        '--' . $frontera,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($texto), 76, "\r\n"),
        '--' . $frontera,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($html), 76, "\r\n"),
        '--' . $frontera . '--',
    ];

    return implode("\r\n", $cabeceras) . "\r\n\r\n" . implode("\r\n", $partes);
}

function fechaCorta(string $iso): string
{
    try {
        return (new DateTimeImmutable($iso))->format('d/m/Y');
    } catch (Throwable) {
        return substr($iso, 0, 10);
    }
}
