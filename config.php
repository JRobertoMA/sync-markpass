<?php

/**
 * Configuración de la aplicación.
 * La API key se lee de .env (ver .env.example) o de la variable de entorno ANTHROPIC_API_KEY.
 * Nunca se escribe en este fichero: sin clave, ClaudeApiCategorizer lanza RuntimeException
 * y api/categorize.php la reporta; el categorizador heurístico sigue funcionando.
 */

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val, " \t\"'");
    }
}

define('ANTHROPIC_API_KEY', $_ENV['ANTHROPIC_API_KEY'] ?? (getenv('ANTHROPIC_API_KEY') ?: ''));

// Modelo a usar para categorización
define('CLAUDE_MODEL', 'claude-haiku-4-5');

// Máximo de marcadores por lote enviado a la API
define('CATEGORIZE_BATCH_SIZE', 60);

// Valor por defecto del campo «máximo a procesar» de la UI. Ya no es un tope duro:
// el worker en segundo plano puede procesar todo lo pendiente (ver cli/worker.php).
define('CATEGORIZE_CLAUDE_MAX_PER_RUN', 300);

// Segundos estimados por lote enviado a Claude. Solo se usa para la estimación previa
// que ve el usuario antes de lanzar el job; medido sobre lotes de CATEGORIZE_BATCH_SIZE.
define('CATEGORIZE_SECONDS_PER_BATCH', 8);

// ── Rutas ────────────────────────────────────────────────────────────────────
define('SB_BASE_DIR', __DIR__);
define('SB_DATA_DIR', __DIR__ . '/data');
define('SB_JOBS_DIR', SB_DATA_DIR . '/jobs');
define('SB_LOG_DIR',  __DIR__ . '/logs');

// Binario de PHP con el que se lanza cli/worker.php desde la web.
// Bajo apache2handler PHP_BINARY viene vacío, así que se fija la ruta del CLI del contenedor.
define('SB_PHP_BIN', (PHP_SAPI === 'cli' && PHP_BINARY) ? PHP_BINARY : '/usr/local/bin/php');
