<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/PipelineManager.php';
require_once __DIR__ . '/../src/DecklinkPortManager.php';
require_once __DIR__ . '/../src/SystemStats.php';

header('Content-Type: application/json');

$db = new Database(__DIR__ . '/../data/pipelines.sqlite');
$manager = new PipelineManager($db->pdo());
$ports = new DecklinkPortManager($db->pdo());

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            echo json_encode($manager->list());
            break;

        case 'create':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $manager->create($data);
            echo json_encode(['id' => $id, 'success' => true]);
            break;

        case 'start':
            $id = (int)($_GET['id'] ?? 0);
            $manager->start($id);
            echo json_encode(['success' => true]);
            break;

        case 'stop':
            $id = (int)($_GET['id'] ?? 0);
            $manager->stop($id);
            echo json_encode(['success' => true]);
            break;

        case 'update':
            $id = (int)($_GET['id'] ?? 0);
            $data = json_decode(file_get_contents('php://input'), true);
            $manager->update($id, $data);
            echo json_encode(['success' => true]);
            break;

        case 'upload':
            if (!isset($_FILES['file'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Keine Datei empfangen']);
                break;
            }
            $uploadDir = __DIR__ . '/../data/uploads/';
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['file']['name']);
            $targetPath = $uploadDir . time() . '_' . $safeName;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
                echo json_encode(['success' => true, 'path' => $targetPath, 'name' => $safeName]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Upload fehlgeschlagen']);
            }
            break;

        case 'list_files':
            $uploadDir = __DIR__ . '/../data/uploads/';
            $files = [];
            foreach (glob($uploadDir . '*') as $filePath) {
                $files[] = [
                    'path' => $filePath,
                    'name' => basename($filePath),
                    'size' => filesize($filePath),
                ];
            }
            echo json_encode($files);
            break;

        case 'stats':
            $id = (int)($_GET['id'] ?? 0);
            echo json_encode($manager->getStats($id));
            break;

        case 'system_stats':
            echo json_encode(SystemStats::collect());
            break;

        case 'decklink_probe':
            $device = (int)($_GET['device'] ?? 0);
            $connection = $_GET['connection'] ?? 'auto';
            echo json_encode($manager->probeDecklinkSignal($device, $connection));
            break;

        case 'decklink_ports_list':
            echo json_encode($ports->list());
            break;

        case 'decklink_ports_update':
            $data = json_decode(file_get_contents('php://input'), true);
            $newPorts = $data['ports'] ?? [];
            $currentRoleByDevice = [];
            foreach ($ports->list() as $currentPort) {
                $currentRoleByDevice[(int)$currentPort['device_number']] = $currentPort['role'];
            }
            $runningPipelines = array_filter($manager->list(), fn($p) => $p['status'] === 'running');
            foreach ($newPorts as $newPort) {
                $deviceNumber = (int)($newPort['device_number'] ?? -1);
                $newRole = $newPort['role'] ?? 'unused';
                if ($newRole === ($currentRoleByDevice[$deviceNumber] ?? 'unused')) {
                    continue;
                }
                foreach ($runningPipelines as $runningPipeline) {
                    $direction = $runningPipeline['pipeline_direction'] ?? 'sdi_to_srt';
                    $isBoundToDevice = ($direction === 'srt_to_sdi' || $runningPipeline['source_type'] === 'decklink')
                        && (int)$runningPipeline['decklink_device_number'] === $deviceNumber;
                    if ($isBoundToDevice) {
                        throw new RuntimeException("Anschluss {$deviceNumber} hat eine laufende Pipeline - bitte zuerst stoppen, bevor die Rolle geändert wird.");
                    }
                }
            }
            $ports->update($newPorts);
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $id = (int)($_GET['id'] ?? 0);
            $manager->delete($id);
            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unbekannte Aktion']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
