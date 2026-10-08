<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/PipelineManager.php';
require_once __DIR__ . '/../src/DecklinkPortManager.php';
require_once __DIR__ . '/../src/SystemStats.php';
require_once __DIR__ . '/../src/NetworkManager.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/FirewallManager.php';
require_once __DIR__ . '/../src/StreamStats.php';
require_once __DIR__ . '/../src/PeerInfo.php';
require_once __DIR__ . '/../src/PowerManager.php';

header('Content-Type: application/json');

Auth::handle();

$db = new Database(__DIR__ . '/../data/pipelines.sqlite');
$manager = new PipelineManager($db->pdo());
$ports = new DecklinkPortManager($db->pdo());

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            echo json_encode($manager->list());
            break;

        case 'system_info':
            echo json_encode(PeerInfo::get() + ['power' => PowerManager::status()]);
            break;

        case 'power_schedule':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Nur per POST']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(PowerManager::schedule(is_array($data) ? $data : []));
            break;

        case 'power_cancel':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Nur per POST']);
                break;
            }
            echo json_encode(PowerManager::cancel());
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

        case 'preview_image':
            $id = (int)($_GET['id'] ?? 0);
            $path = PipelineManager::previewImagePath($id);
            if (!file_exists($path)) {
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Keine Vorschau vorhanden']);
                break;
            }
            header('Content-Type: image/jpeg');
            header('Cache-Control: no-store');
            readfile($path);
            break;

        case 'decklink_probe':
            $device = (int)($_GET['device'] ?? 0);
            $connection = $_GET['connection'] ?? 'auto';
            echo json_encode($manager->probeDecklinkSignal($device, $connection, ($_GET['fresh'] ?? '') === '1'));
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
            $allPipelines = $manager->list();
            $runningPipelines = array_filter($allPipelines, fn($p) => $p['status'] === 'running' || $p['distributor_status'] === 'running');
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
                        throw new RuntimeException("Anschluss " . ($deviceNumber + 1) . " hat eine laufende Pipeline - bitte zuerst stoppen, bevor die Rolle geändert wird.");
                    }
                }
                // Zusaetzliche SRT-Ziele (Advanced-Menue) laufen als eigene Prozesse, unabhaengig vom
                // status-Feld der Pipeline selbst - auch die muessen vor einem Rollenwechsel stehen.
                foreach ($allPipelines as $pipeline) {
                    if ((int)$pipeline['decklink_device_number'] !== $deviceNumber) {
                        continue;
                    }
                    foreach ($manager->listTargets((int)$pipeline['id']) as $target) {
                        if ($target['status'] === 'running') {
                            throw new RuntimeException("Anschluss " . ($deviceNumber + 1) . " hat ein laufendes SRT-Ziel (\"{$target['name']}\") - bitte zuerst stoppen, bevor die Rolle geändert wird.");
                        }
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

        // Zusaetzliche SRT-Ziele fuer SDI-Eingaenge (Advanced-Menue) - "Ziel 1" ist weiterhin die
        // Pipeline selbst (create/update/start/stop oben), diese Aktionen betreffen nur Ziel 2, 3, ...
        case 'targets_list':
            $pipelineId = (int)($_GET['pipeline_id'] ?? 0);
            echo json_encode($manager->listTargets($pipelineId));
            break;

        case 'target_create':
            $pipelineId = (int)($_GET['pipeline_id'] ?? 0);
            $data = json_decode(file_get_contents('php://input'), true);
            $targetId = $manager->createTarget($pipelineId, $data);
            echo json_encode(['id' => $targetId, 'success' => true]);
            break;

        case 'target_update':
            $id = (int)($_GET['id'] ?? 0);
            $data = json_decode(file_get_contents('php://input'), true);
            $manager->updateTarget($id, $data);
            echo json_encode(['success' => true]);
            break;

        case 'target_delete':
            $id = (int)($_GET['id'] ?? 0);
            $manager->deleteTarget($id);
            echo json_encode(['success' => true]);
            break;

        case 'target_start':
            $id = (int)($_GET['id'] ?? 0);
            $manager->startTarget($id);
            echo json_encode(['success' => true]);
            break;

        case 'target_stop':
            $id = (int)($_GET['id'] ?? 0);
            $manager->stopTarget($id);
            echo json_encode(['success' => true]);
            break;

        case 'target_stats':
            $id = (int)($_GET['id'] ?? 0);
            echo json_encode($manager->getTargetStats($id));
            break;

        case 'network_list':
            echo json_encode(NetworkManager::list());
            break;

        case 'network_apply':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(NetworkManager::apply($data['interfaces'] ?? []));
            break;

        case 'network_confirm':
            echo json_encode(NetworkManager::confirm());
            break;

        case 'stats_overview':
            echo json_encode(StreamStats::collect($manager));
            break;

        case 'firewall_status':
            echo json_encode(FirewallManager::status($manager));
            break;

        case 'firewall_add':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(FirewallManager::add(is_array($data) ? $data : []));
            break;

        case 'firewall_delete':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(FirewallManager::delete(is_array($data) ? $data : []));
            break;

        case 'firewall_confirm':
            echo json_encode(FirewallManager::confirm());
            break;

        case 'firewall_rollback':
            echo json_encode(FirewallManager::rollback());
            break;

        case 'vpn_connect':
            echo json_encode(NetworkManager::vpnConnect());
            break;

        case 'vpn_reconnect':
            echo json_encode(NetworkManager::vpnReconnect());
            break;

        case 'network_rollback':
            echo json_encode(NetworkManager::rollback());
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unbekannte Aktion']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
