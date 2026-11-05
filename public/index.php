<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use CommitLab\Failure;
use CommitLab\NamespaceStore;
use CommitLab\Repository;

function respond(int $status, array $value): never
{
    $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

try {
    if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
        throw new Failure(403, 'loopback_only', 'Only loopback requests are accepted.');
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_contains($uri, '?')) {
        throw new Failure(400, 'query_not_supported', 'These routes do not accept query parameters.');
    }
    $repository = new Repository(NamespaceStore::open((string) getenv('LAB_ROOT')));
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($uri === '/batches') {
        if ($method !== 'POST') {
            header('Allow: POST');
            throw new Failure(405, 'method_not_allowed', 'Use POST.');
        }
        respond(201, $repository->createBatch());
    }
    if (preg_match('~\A/batches/([0-9a-f]{32})\z~D', $uri, $match)) {
        if ($method !== 'GET') {
            header('Allow: GET');
            throw new Failure(405, 'method_not_allowed', 'Use GET.');
        }
        respond(200, $repository->getBatch($match[1]));
    }
    if (preg_match('~\A/objects/([0-9a-f]{64})\z~D', $uri, $match)) {
        if ($method !== 'GET') {
            header('Allow: GET');
            throw new Failure(405, 'method_not_allowed', 'Use GET.');
        }
        $object = $repository->readObject($match[1]);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Content-Length: ' . $object['size']);
        $remaining = $object['size'];
        try {
            while ($remaining > 0) {
                $bytes = fread($object['stream'], min(16384, $remaining));
                if ($bytes === false || $bytes === '') {
                    error_log('commit-lab: object stream interrupted');
                    break;
                }
                echo $bytes;
                $remaining -= strlen($bytes);
            }
        } finally {
            fclose($object['stream']);
        }
        exit;
    }
    throw new Failure(404, 'not_found', 'Route not found.');
} catch (Failure $failure) {
    respond($failure->status, ['error' => ['code' => $failure->kind, 'message' => $failure->getMessage()]]);
} catch (Throwable) {
    error_log('commit-lab: request failed with an internal storage or runtime error');
    respond(503, ['error' => ['code' => 'storage_unavailable', 'message' => 'The operation could not be completed. Inspect local diagnostics.']]);
}
