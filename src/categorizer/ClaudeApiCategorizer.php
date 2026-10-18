<?php

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Database.php';

use Anthropic\Client;

/**
 * Categorizador con Claude API.
 *
 * Envía lotes de marcadores a Claude Opus 4.6 y pide que asigne
 * una ruta de carpeta jerárquica a cada uno, usando como contexto
 * las carpetas que ya existen en la BD.
 */
class ClaudeApiCategorizer {

    private Client $client;
    private int    $batchSize;

    public function __construct() {
        $apiKey = ANTHROPIC_API_KEY;
        if (empty($apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY no está configurada.');
        }
        $this->client    = new Client(apiKey: $apiKey);
        $this->batchSize = CATEGORIZE_BATCH_SIZE;
    }

    /**
     * @param array[]       $bookmarks Filas de BD (url, title, folder_path, source)
     * @param callable|null $onBatch   fn(array $batchResults, int $index, int $totalBatches): ?bool
     *                                 Se llama tras cada lote para reportar progreso;
     *                                 si devuelve false, se detiene (cancelación desde el worker).
     * @return array[] Mismas filas + 'suggested_folder' y 'confidence'
     */
    public function categorize(array $bookmarks, ?callable $onBatch = null): array {
        $db = Database::getInstance();
        $existingFolders = $db->query(
            "SELECT DISTINCT folder_path FROM bookmarks WHERE folder_path != '/' ORDER BY folder_path"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $results = [];
        $batches = array_chunk($bookmarks, $this->batchSize);
        $total   = count($batches);

        foreach ($batches as $i => $batch) {
            $batchResults = $this->processBatch($batch, $existingFolders);
            $results      = array_merge($results, $batchResults);

            if ($onBatch !== null && $onBatch($batchResults, $i + 1, $total) === false) {
                break;
            }
        }

        return $results;
    }

    /** Nº de lotes en que se dividiría esta lista (para la estimación previa). */
    public function batchCount(int $bookmarks): int {
        return (int) ceil($bookmarks / max(1, $this->batchSize));
    }

    private function processBatch(array $batch, array $existingFolders = []): array {
        // Build bookmark list for the prompt
        $lines = [];
        foreach ($batch as $i => $bm) {
            $title = $bm['title'] ?: '(sin título)';
            $url   = $bm['url']   ?: '';
            $lines[] = ($i + 1) . ". title=\"{$title}\" url=\"{$url}\"";
        }
        $bookmarkList = implode("\n", $lines);

        $folderContext = '';
        if (!empty($existingFolders)) {
            $folderList   = implode("\n", array_map(fn($f) => "- {$f}", array_slice($existingFolders, 0, 80)));
            $folderContext = "\n\nCarpetas ya existentes (reutilízalas cuando encajen):\n{$folderList}";
        }

        $prompt = <<<PROMPT
Eres un asistente para organizar marcadores del navegador. Tu tarea es asignar una ruta de carpeta jerárquica a cada marcador.

Reglas:
- Reutiliza las carpetas existentes cuando el contenido encaje; solo crea carpetas nuevas si ninguna existente es apropiada
- Usa rutas jerárquicas con "/" (ej: /Dev/PHP, /Videos/YouTube, /Noticias/Tech)
- Los nombres de carpetas deben ser concisos (1-3 palabras, sin espacios al inicio/fin)
- Agrupa contenido relacionado bajo la misma carpeta padre
- Usa español para los nombres de carpetas
- Devuelve ÚNICAMENTE un objeto JSON válido: {"1": "/Ruta/Carpeta", "2": "/Ruta/Carpeta", ...}
- Las claves son los números de la lista. No incluyas texto fuera del JSON.{$folderContext}

Marcadores a clasificar:
{$bookmarkList}
PROMPT;

        try {
            $message = $this->client->messages->create(
                model:     CLAUDE_MODEL,
                maxTokens: 4096,
                messages:  [
                    ['role' => 'user', 'content' => $prompt],
                ],
            );

            // Extract text from response (may contain thinking blocks)
            $text = '';
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $text = $block->text;
                    break;
                }
            }

            $suggestions = $this->parseResponse($text);
            return $this->mergeResults($batch, $suggestions);

        } catch (\Exception $e) {
            // On error, return bookmarks uncategorized
            return array_map(fn($bm) => array_merge($bm, [
                'suggested_folder' => '/Sin categorizar',
                'confidence'       => 0.0,
                'engine'           => 'claude',
                'error'            => $e->getMessage(),
            ]), $batch);
        }
    }

    /**
     * Parses Claude's JSON response, handling common formatting variations.
     * Returns array indexed 1-based: ["1" => "/folder", ...]
     */
    private function parseResponse(string $text): array {
        $text = trim($text);

        // 1. Strip markdown code fences (```json ... ``` or ``` ... ```)
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/', '', $text);
        $text = trim($text);

        // 2. Try decoding the whole text directly
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 3. Extract the first complete JSON object via balanced-brace scanning
        $extracted = $this->extractFirstJsonObject($text);
        if ($extracted !== null) {
            $decoded = json_decode($extracted, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function extractFirstJsonObject(string $text): ?string {
        $start = strpos($text, '{');
        if ($start === false) return null;

        $depth  = 0;
        $len    = strlen($text);
        $inStr  = false;
        $escape = false;

        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];

            if ($escape) { $escape = false; continue; }
            if ($ch === '\\' && $inStr) { $escape = true; continue; }
            if ($ch === '"') { $inStr = !$inStr; continue; }
            if ($inStr) continue;

            if ($ch === '{') $depth++;
            elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) return substr($text, $start, $i - $start + 1);
            }
        }

        return null;
    }

    private function mergeResults(array $batch, array $suggestions): array {
        $results = [];
        foreach ($batch as $i => $bm) {
            $key     = (string)($i + 1);
            $folder  = $suggestions[$key] ?? null;

            // Normalize folder path
            if ($folder) {
                $folder = '/' . trim($folder, '/');
            }

            $results[] = array_merge($bm, [
                'suggested_folder' => $folder ?? '/Sin categorizar',
                'confidence'       => $folder ? 0.85 : 0.0,
                'engine'           => 'claude',
            ]);
        }
        return $results;
    }
}
