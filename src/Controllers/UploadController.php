<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Env;
use PublishGo\Core\HttpException;
use PublishGo\Core\Request;

/**
 * Upload de imagens (logo, comprovantes, selfies). Salva em public/uploads
 * e retorna a URL pública (com base path).
 */
final class UploadController extends Controller
{
    private const ALLOWED = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/svg+xml' => 'svg', 'application/pdf' => 'pdf',
    ];
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function image(Request $request): mixed
    {
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            throw HttpException::unprocessable('Nenhum arquivo enviado (campo "file").');
        }
        $file = $_FILES['file'];
        if ($file['size'] > self::MAX_BYTES) {
            throw HttpException::unprocessable('Arquivo muito grande (máx. 4MB).');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        if (!isset(self::ALLOWED[$mime])) {
            throw HttpException::unprocessable('Formato inválido. Use JPG, PNG, WEBP ou PDF.');
        }
        $ext = self::ALLOWED[$mime];

        $dir = dirname(__DIR__, 2) . '/public/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $companyId = (int) (($request->auth['company_id'] ?? null) ?? 0);
        $name = 'c' . $companyId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target = $dir . '/' . $name;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new HttpException('Falha ao salvar o arquivo.', 500);
        }
        @chmod($target, 0644);

        $base = rtrim((string) Env::get('APP_BASE', ''), '/');
        return ['url' => $base . '/uploads/' . $name];
    }
}
