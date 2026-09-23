<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * Cola de jobs de categorización: un fichero JSON por job en data/jobs/<id>.json.
 * Los procesa cli/worker.php (un único proceso a la vez, fuera de Apache), para no bloquear
 * un worker de mpm_prefork durante minutos. Escrituras atómicas (tmp + rename).
 *
 * Mismo patrón que htdocs/zerochan-gallery-downloader/src/Jobs.php.
 */
class Jobs {
    public const ACTIVE    = ['queued', 'running'];
    public const TYPES     = ['categorize', 'linkcheck'];
    public const LOCK_FILE = SB_DATA_DIR . '/worker.lock';

    public function __construct() {
        if (!is_dir(SB_JOBS_DIR)) {
            mkdir(SB_JOBS_DIR, 0775, true);
        }
    }

    /**
     * Crea un job en cola.
     *
     * @param string $type   categorize | linkcheck
     * @param array  $params campos propios del tipo (engine, source, max, batch_size, scope…)
     * @param int    $total  elementos que se van a procesar, para la barra de progreso
     */
    public function create(string $type, array $params, int $total): array {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Tipo de job no válido: $type");
        }

        $job = array_merge([
            'id'           => date('Ymd-His') . '-' . substr(uniqid(), -4),
            'type'         => $type,
            'created_at'   => date('c'),
            'updated_at'   => date('c'),
            'status'       => 'queued',
            'total'        => $total,
            'done'         => 0,
            'batches'      => 0,
            'batches_done' => 0,
            'results'      => [],
            'warning'      => null,
            'error'        => null,
            'cancel'       => false,
            'pid'          => null,
        ], $params);

        $this->save($job);
        return $job;
    }

    /** Tipo del job; los creados antes de que existieran los tipos son de categorización. */
    public static function typeOf(array $job): string {
        return $job['type'] ?? 'categorize';
    }

    public function get(string $id): ?array {
        $file = $this->file($id);
        if (!$file || !is_file($file)) {
            return null;
        }
        $job = json_decode((string) file_get_contents($file), true);
        return is_array($job) ? $job : null;
    }

    /** Todos los jobs, más antiguos primero. */
    public function all(): array {
        $jobs = [];
        foreach (glob(SB_JOBS_DIR . '/*.json') ?: [] as $f) {
            $j = json_decode((string) file_get_contents($f), true);
            if (is_array($j)) $jobs[] = $j;
        }
        usort($jobs, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
        return $jobs;
    }

    /**
     * Job que impide aceptar otro ahora mismo. Los que ya están cancelándose no cuentan:
     * la cola es secuencial, así que el worker cogerá el nuevo en cuanto suelte el actual.
     */
    public function blocking(): ?array {
        $busy = array_filter(
            $this->all(),
            fn($j) => in_array($j['status'], self::ACTIVE, true) && empty($j['cancel'])
        );
        return $busy ? array_values($busy)[0] : null;
    }

    /** Siguiente job a procesar: primero los que ya estaban en marcha (reanudación). */
    public function next(): ?array {
        $pending = array_filter($this->all(), fn($j) => in_array($j['status'], self::ACTIVE, true));
        usort($pending, fn($a, $b) => ($a['status'] === 'queued') <=> ($b['status'] === 'queued'));
        return $pending ? array_values($pending)[0] : null;
    }

    /**
     * Job que debe ver la UI: el que el worker está procesando de verdad.
     * Entre los activos van primero los que no se están cancelando y los ya en marcha,
     * para que al cancelar uno y encolar otro se muestre el nuevo, no el que agoniza.
     */
    public function current(): ?array {
        $active = array_values(array_filter($this->all(), fn($j) => in_array($j['status'], self::ACTIVE, true)));
        if ($active) {
            usort($active, fn($a, $b) =>
                [!empty($a['cancel']), $a['status'] === 'queued']
                <=> [!empty($b['cancel']), $b['status'] === 'queued']
            );
            return $active[0];
        }
        $all = $this->all();
        return $all ? end($all) : null;
    }

    public function save(array $job): void {
        $job['updated_at'] = date('c');
        $file = $this->file($job['id']);
        // El worker trabaja sobre su copia en memoria; no debe pisar una cancelación pedida desde la API
        if (empty($job['cancel']) && is_file($file)) {
            $onDisk = json_decode((string) file_get_contents($file), true);
            if (!empty($onDisk['cancel'])) {
                $job['cancel'] = true;
            }
        }
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($tmp, $file);
    }

    /** Marca la cancelación; el worker la aplica al terminar el lote en curso. */
    public function cancel(string $id): ?array {
        $job = $this->get($id);
        if (!$job) return null;
        if (in_array($job['status'], self::ACTIVE, true)) {
            $job['cancel'] = true;
            if ($job['status'] === 'queued') {
                $job['status'] = 'cancelled';
            }
            $this->save($job);
        }
        return $job;
    }

    /** Borra los jobs terminados (done/cancelled/error). */
    public function clearFinished(): int {
        $n = 0;
        foreach ($this->all() as $j) {
            if (!in_array($j['status'], self::ACTIVE, true) && @unlink($this->file($j['id']))) $n++;
        }
        return $n;
    }

    /** Resumen ligero para listados y polling: sin las sugerencias, que pueden ser miles. */
    public static function summary(array $job): array {
        $job['has_results'] = !empty($job['results']);
        unset($job['results']);
        return $job;
    }

    /** true si hay un worker con el lock cogido. */
    public function workerRunning(): bool {
        $fh = @fopen(self::LOCK_FILE, 'c');
        if (!$fh) return false;
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) flock($fh, LOCK_UN);
        fclose($fh);
        return !$free;
    }

    /** Lanza cli/worker.php desacoplado de la petición HTTP si no hay uno corriendo. */
    public function spawnWorker(): bool {
        if ($this->workerRunning()) {
            return false;
        }
        if (!is_dir(SB_LOG_DIR)) {
            mkdir(SB_LOG_DIR, 0775, true);
        }
        $cmd = sprintf(
            'setsid nohup %s %s >> %s 2>&1 &',
            escapeshellarg(SB_PHP_BIN),
            escapeshellarg(SB_BASE_DIR . '/cli/worker.php'),
            escapeshellarg(SB_LOG_DIR . '/worker.log')
        );
        exec($cmd);
        return true;
    }

    private function file(string $id): ?string {
        return preg_match('/^[0-9]{8}-[0-9]{6}-[a-z0-9]{4}$/', $id) ? SB_JOBS_DIR . "/$id.json" : null;
    }
}
