#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Worker de la cola de jobs. Un único proceso a la vez (flock en data/worker.lock).
 * Lo lanzan los endpoints al crear un job; también sirve como red de seguridad por cron.
 *
 * Solo despacha: la lógica de cada tipo vive en su runner.
 * Debe correr como www-data, igual que Apache:
 *   docker exec -u www-data php-apache php /var/www/html/sync-bookmarks/cli/worker.php
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo CLI\n");
}

require_once __DIR__ . '/../src/Jobs.php';
require_once __DIR__ . '/../src/categorizer/CategorizeJobRunner.php';
require_once __DIR__ . '/../src/link/LinkCheckJobRunner.php';

set_time_limit(0);

$lock = fopen(Jobs::LOCK_FILE, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Ya hay un worker en marcha\n";
    exit(0);
}

$jobs = new Jobs();
log_line('worker_start pid=' . getmypid());

while ($job = $jobs->next()) {
    $type = Jobs::typeOf($job);

    $job['status'] = 'running';
    $job['pid']    = getmypid();
    $jobs->save($job);
    log_line("job_start {$job['id']} type=$type");

    try {
        $job = match ($type) {
            'linkcheck'  => (new LinkCheckJobRunner($jobs, 'log_line'))->run($job),
            'categorize' => (new CategorizeJobRunner($jobs, 'log_line'))->run($job),
            default      => throw new RuntimeException("Tipo de job desconocido: $type"),
        };

        $jobs->save($job);
        log_line("job_{$job['status']} {$job['id']}");

    } catch (Throwable $e) {
        $job['status'] = 'error';
        $job['error']  = $e->getMessage();
        $jobs->save($job);
        log_line("job_error {$job['id']} " . $e->getMessage());
    }
}

log_line('worker_end');
flock($lock, LOCK_UN);
fclose($lock);

function log_line(string $msg): void {
    echo '[' . date('c') . "] $msg\n";
}
