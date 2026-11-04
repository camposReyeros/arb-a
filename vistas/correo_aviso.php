<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * Correo de aviso, adaptado del esqueleto de Stitch "Descarga directa".
 *
 * Reglas de correo: todo el estilo va en línea, sin <style>, sin JavaScript, sin
 * imágenes externas ni fuentes descargables (Gmail y Outlook las recortan). El
 * diseño se sostiene con tablas y anchos fijos de 600 px.
 */
function avisoHtml(string $nombre, string $link, string $expiraLink, string $expira, int $bytes): string
{
    $fechas = avisoFechas($expiraLink);
    $fechaArchivo = avisoFechas($expira)['corta'];
    $tamano = avisoTamano($bytes);

    $titulo = e($nombre);
    $url = e($link);
    $dias = $fechas['dias'];
    $avisoDias = $dias === 1 ? 'Vence mañana' : 'Vence en ' . $dias . ' días';

    return '<!DOCTYPE html>'
        . '<html lang="es-MX"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>ARB-A — ' . $titulo . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f8f9fa;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'
        . 'El archivo ' . $titulo . ' ya está disponible. Enlace vigente hasta el ' . e($fechas['corta']) . '.</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="background:#f8f9fa;"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" '
        . 'style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e0e2ec;border-radius:16px;">'

        // Encabezado: marca + distintivo.
        . '<tr><td style="padding:20px 24px;border-bottom:1px solid #eceef3;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td style="font:700 20px Arial,Helvetica,sans-serif;letter-spacing:-0.2px;color:#1f1f1f;">ARB-A</td>'
        . '<td align="right"><span style="display:inline-block;padding:5px 10px;background:#f1f3f4;'
        . 'border-radius:999px;font:500 12px Arial,Helvetica,sans-serif;color:#444746;">Descarga segura</span></td>'
        . '</tr></table></td></tr>'

        // Cuerpo.
        . '<tr><td style="padding:24px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:18px;"><tr>'
        . '<td width="48" height="48" align="center" valign="middle" '
        . 'style="width:48px;height:48px;background:#d3e3fd;border-radius:12px;'
        . 'font:700 13px Arial,Helvetica,sans-serif;color:#0b57d0;">CSV</td>'
        . '</tr></table>'
        . '<div style="font:600 11px Arial,Helvetica,sans-serif;letter-spacing:1px;'
        . 'text-transform:uppercase;color:#0b57d0;">Archivo disponible</div>'
        . '<div style="margin:6px 0 8px;font:700 18px \'SFMono-Regular\',Menlo,Consolas,monospace;'
        . 'line-height:1.35;word-break:break-all;color:#1f1f1f;">' . $titulo . '</div>'
        . '<div style="font:400 14px/1.6 Arial,Helvetica,sans-serif;color:#444746;">'
        . 'El archivo ya está listo para descargar.</div>'

        // Ficha: tamaño, formato y vigencia.
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="margin:20px 0;background:#f8f9fa;border:1px solid #eceef3;border-radius:12px;">'
        . '<tr><td style="padding:12px 14px;font:400 13px/1.7 Arial,Helvetica,sans-serif;color:#444746;">'
        . 'Tamaño: <strong style="color:#1f1f1f;">' . e($tamano) . '</strong><br>'
        . 'Formato: <strong style="color:#1f1f1f;">CSV</strong><br>'
        . 'Enlace vigente: <strong style="color:#1f1f1f;">' . $dias . ' días</strong>'
        . '</td></tr></table>'

        // Aviso de vigencia (ámbar del esqueleto).
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="margin:0 0 22px;background:#fef7e0;border:1px solid #f5e3b3;border-radius:12px;">'
        . '<tr><td style="padding:12px 14px;font:400 12px/1.6 Arial,Helvetica,sans-serif;color:#7c4a00;">'
        . '<strong style="display:block;margin-bottom:2px;font-size:13px;color:#5b3600;">' . $avisoDias . '</strong>'
        . 'Este enlace directo vence el <strong>' . e($fechas['corta']) . '</strong>. '
        . 'Después podrá descargar el archivo desde el portal con su usuario de siempre, '
        . 'hasta el <strong>' . e($fechaArchivo) . '</strong>.'
        . '</td></tr></table>'

        // Botón principal.
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td align="center" style="padding:0 0 10px;">'
        . '<a href="' . $url . '" style="display:inline-block;padding:14px 30px;background:#0b57d0;'
        . 'border-radius:999px;color:#ffffff;text-decoration:none;'
        . 'font:600 14px Arial,Helvetica,sans-serif;">Descargar archivo (' . e($tamano) . ')</a>'
        . '</td></tr></table>'

        // Enlace visible para copiar si el botón no abre.
        . '<div style="margin-top:14px;font:400 11px/1.6 Arial,Helvetica,sans-serif;color:#747775;'
        . 'text-align:center;word-break:break-all;">'
        . 'Si el botón no abre, copia este enlace:<br><a href="' . $url . '" '
        . 'style="color:#0b57d0;text-decoration:none;">' . $url . '</a></div>'

        . '</td></tr>'

        // Pie.
        . '<tr><td style="padding:16px 24px;border-top:1px solid #eceef3;'
        . 'font:400 12px/1.6 Arial,Helvetica,sans-serif;color:#747775;">'
        . 'Portal ARB-A · servicios.care<span style="color:#c4c7c5;"> · </span>Acceso auditado'
        . '</td></tr>'

        . '</table></td></tr></table></body></html>';
}

/** @return array{corta:string,dias:int} */
function avisoFechas(string $iso): array
{
    $ahora = new DateTimeImmutable('now');
    $fecha = avisoFechaObjeto($iso);
    $dias = $fecha === null ? 0 : max(0, (int) ceil(($fecha->getTimestamp() - $ahora->getTimestamp()) / 86400));

    return ['corta' => $fecha === null ? substr($iso, 0, 10) : $fecha->format('d/m/Y'), 'dias' => $dias];
}

function avisoFechaObjeto(string $iso): ?DateTimeImmutable
{
    try {
        return new DateTimeImmutable($iso);
    } catch (Throwable) {
        return null;
    }
}

function avisoTamano(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, '.', '') . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0, '.', '') . ' KB';
    }

    return $bytes . ' B';
}
