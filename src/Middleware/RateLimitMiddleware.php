<?php

declare(strict_types=1);

namespace PublishGo\Middleware;

use PublishGo\Core\Env;
use PublishGo\Core\HttpException;
use PublishGo\Core\Request;

/**
 * Rate limiting por IP usando arquivos de bucket (sem dependência de Redis).
 * Janela deslizante simples baseada em contadores por intervalo.
 */
final class RateLimitMiddleware implements Middleware
{
    public function handle(Request $request): void
    {
        $max = Env::int('RATE_LIMIT_MAX', 120);
        $window = Env::int('RATE_LIMIT_WINDOW', 60);
        if ($max <= 0) {
            return;
        }

        $dir = sys_get_temp_dir() . '/publishgo_ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $bucket = (int) floor(time() / $window);
        $key = hash('sha256', $request->ip() . '|' . $bucket);
        $file = $dir . '/' . $key;

        $count = 0;
        $fp = @fopen($file, 'c+');
        if ($fp !== false) {
            flock($fp, LOCK_EX);
            $contents = stream_get_contents($fp) ?: '0';
            $count = (int) $contents + 1;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) $count);
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        $remaining = max(0, $max - $count);
        header("X-RateLimit-Limit: {$max}");
        header("X-RateLimit-Remaining: {$remaining}");

        if ($count > $max) {
            throw new HttpException('Muitas requisições. Tente novamente em instantes.', 429);
        }

        // Limpeza oportunista de buckets antigos.
        if (random_int(1, 50) === 1) {
            foreach (glob($dir . '/*') ?: [] as $old) {
                if (is_file($old) && filemtime($old) < time() - ($window * 3)) {
                    @unlink($old);
                }
            }
        }
    }
}
