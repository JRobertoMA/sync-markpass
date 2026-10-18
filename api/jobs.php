<?php

declare(strict_types=1);

/**
 * Estado de la cola de categorización.
 *   GET  api/jobs.php             → { job: <actual o último>, worker_running }
 *   GET  api/jobs.php?id=X        → resumen de ese job (sin las sugerencias)
 *   GET  api/jobs.php?id=X&results=1 → sugerencias completas del job terminado
 *   POST action=cancel&id=X | action=clear
 */

require_once __DIR__ . '/../src/Jobs.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$jobs = new Jobs();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = isset($_GET['id']) ? (string) $_GET['id'] : '';

    if ($id !== '') {
        $job = $jobs->get($id);
        if (!$job) {
            http_response_code(404);
            echo json_encode(['error' => 'Job no encontrado']);
            exit;
        }
        if (isset($_GET['results'])) {
            echo json_encode([
                'id'          => $job['id'],
                'status'      => $job['status'],
                'total'       => $job['total'],
                'changed'     => $job['changed'],
                'warning'     => $job['warning'],
                'suggestions' => $job['results'] ?? [],
            ]);
            exit;
        }
        echo json_encode(['job' => Jobs::summary($job), 'worker_running' => $jobs->workerRunning()]);
        exit;
    }

    $current = $jobs->current();
    echo json_encode([
        'job'            => $current ? Jobs::summary($current) : null,
        'worker_running' => $jobs->workerRunning(),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $body['action'] ?? '';

switch ($action) {
    case 'cancel':
        $job = $jobs->cancel((string) ($body['id'] ?? ''));
        if (!$job) {
            http_response_code(404);
            echo json_encode(['error' => 'Job no encontrado']);
            exit;
        }
        echo json_encode(['success' => true, 'job' => Jobs::summary($job)]);
        exit;

    case 'clear':
        echo json_encode(['success' => true, 'cleared' => $jobs->clearFinished()]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
}
