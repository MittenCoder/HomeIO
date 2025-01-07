<?php

require_once __DIR__ . '/../config/config.php';
require $config['sharedpath'].'/logger.php';

$log = new logger(basename(__FILE__, '.php')."_", __DIR__);
$dbConfig = $config['db_config'];

// Button mapping configuration
$buttonConfig = [
    'GV5125615A' => [ // Second remote
        1 => ['device' => 'e5c95310-d979-4484-a524-b7e424e17f88', 'command' => ['name' => 'brightness', 'value' => 30]], // High
        2 => ['device' => '682a0c16-6d63-4877-9c02-f1711691c488', 'command' => ['name' => 'brightness', 'value' => 30]], // High
        3 => ['device' => 'e5c95310-d979-4484-a524-b7e424e17f88', 'command' => ['name' => 'brightness', 'value' => 1]],  // Medium
        4 => ['device' => '682a0c16-6d63-4877-9c02-f1711691c488', 'command' => ['name' => 'brightness', 'value' => 1]],  // Medium
        5 => ['device' => 'e5c95310-d979-4484-a524-b7e424e17f88', 'command' => ['name' => 'turn', 'value' => 'off']],
        6 => ['device' => '682a0c16-6d63-4877-9c02-f1711691c488', 'command' => ['name' => 'turn', 'value' => 'off']]
    ],
    'GV5125207B' => [ // First remote
        2 => ['device' => 'd6831fe8-fe58-4321-8029-2d739f62a055', 'command' => ['name' => 'brightness', 'value' => 100]], // High
        1 => ['device' => 'bab38ad6-9d55-47f7-8094-40f2c6282978', 'command' => ['name' => 'brightness', 'value' => 100]], // High
        4 => ['device' => 'd6831fe8-fe58-4321-8029-2d739f62a055', 'command' => ['name' => 'brightness', 'value' => 50]],  // Medium
        3 => ['device' => 'bab38ad6-9d55-47f7-8094-40f2c6282978', 'command' => ['name' => 'brightness', 'value' => 50]],  // Medium
        6 => ['device' => 'd6831fe8-fe58-4321-8029-2d739f62a055', 'command' => ['name' => 'turn', 'value' => 'off']],
        5 => ['device' => 'bab38ad6-9d55-47f7-8094-40f2c6282978', 'command' => ['name' => 'turn', 'value' => 'off']]
    ],
    'GV5122427B' => [ 
        1 => ['group' => '25', 'command' => ['name' => 'toggle', 'value' => 1]], // Toggle between off and 100% brightness
    ],
    'GV51224B48' => [ 
        1 => ['group' => '25', 'command' => ['name' => 'toggle', 'value' => 1]], // Toggle between off and 100% brightness
    ]
];

function getDatabaseConnection($dbConfig) {
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4";
    
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ];

    try {
        $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], $options);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

function getGroupDevices($pdo, $groupId) {
    $stmt = $pdo->prepare("SELECT devices FROM device_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? json_decode($result['devices'], true) : [];
}

function getCurrentGroupState($pdo, $groupId) {
    // Get all devices in the group
    $devices = getGroupDevices($pdo, $groupId);
    if (empty($devices)) return false;

    // Check the state of the first device (assuming all devices in group are synced)
    $stmt = $pdo->prepare("SELECT powerState FROM devices WHERE device = ?");
    $stmt->execute([$devices[0]]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? $result['powerState'] : false;
}

try {
    $log->logInfoMsg("Starting remote button monitor");
    $pdo = getDatabaseConnection($dbConfig);
    
    while (true) {
        try {
            // Test connection and reconnect if needed
            try {
                $pdo->query("SELECT 1");
            } catch (PDOException $e) {
                $log->logInfoMsg("Reconnecting to database...");
                $pdo = getDatabaseConnection($dbConfig);
            }

            // Get new button presses
            $stmt = $pdo->prepare("
                SELECT id, remote_name, button_number 
                FROM remote_buttons 
                WHERE status = 'received'
                ORDER BY timestamp ASC
                LIMIT 10
            ");
            $stmt->execute();
            $buttons = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($buttons as $button) {
                $remote = $button['remote_name'];
                $buttonNum = (int)$button['button_number'];

                if (isset($buttonConfig[$remote][$buttonNum])) {
                    $config = $buttonConfig[$remote][$buttonNum];
                    
                    try {
                        $pdo->beginTransaction();

                        // Update status to processing
                        $stmt = $pdo->prepare("
                            UPDATE remote_buttons 
                            SET status = 'processing' 
                            WHERE id = ? AND status = 'received'
                        ");
                        $stmt->execute([$button['id']]);

                        if ($stmt->rowCount() > 0) {
                            if (isset($config['group'])) {
                                // Handle group command
                                $groupId = $config['group'];
                                $devices = getGroupDevices($pdo, $groupId);
                                
                                if ($config['command']['name'] === 'toggle') {
                                    // Get current state of the group
                                    $currentState = getCurrentGroupState($pdo, $groupId);
                                    
                                    // Prepare the command based on current state
                                    $command = [
                                        'name' => $currentState === 'on' ? 'turn' : 'brightness',
                                        'value' => $currentState === 'on' ? 'off' : $config['command']['value']
                                    ];
                                    
                                    // Queue command for each device in the group
                                    foreach ($devices as $deviceId) {
                                        $stmt = $pdo->prepare("
                                            INSERT INTO command_queue 
                                            (device, model, command, brand) 
                                            SELECT device, model, :command, brand
                                            FROM devices
                                            WHERE device = :device
                                        ");
                                        $stmt->execute([
                                            'command' => json_encode($command),
                                            'device' => $deviceId
                                        ]);
                                    }
                                }
                            } else {
                                // Handle individual device command
                                $stmt = $pdo->prepare("
                                    SELECT model, brand 
                                    FROM devices 
                                    WHERE device = ?
                                ");
                                $stmt->execute([$config['device']]);
                                $device = $stmt->fetch(PDO::FETCH_ASSOC);

                                if ($device) {
                                    $stmt = $pdo->prepare("
                                        INSERT INTO command_queue 
                                        (device, model, command, brand) 
                                        VALUES (?, ?, ?, ?)
                                    ");
                                    $stmt->execute([
                                        $config['device'],
                                        $device['model'],
                                        json_encode($config['command']),
                                        $device['brand']
                                    ]);
                                }
                            }

                            // Mark as executed
                            $stmt = $pdo->prepare("
                                UPDATE remote_buttons 
                                SET status = 'executed' 
                                WHERE id = ?
                            ");
                            $stmt->execute([$button['id']]);

                            $log->logInfoMsg("Command processed for " . 
                                (isset($config['group']) ? "group {$config['group']}" : "device {$config['device']}"));
                        }
                        
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                } else {
                    // Mark invalid buttons as executed
                    $stmt = $pdo->prepare("
                        UPDATE remote_buttons 
                        SET status = 'executed' 
                        WHERE id = ?
                    ");
                    $stmt->execute([$button['id']]);
                }
            }

            usleep(100000); // 100ms pause
            
        } catch (Exception $e) {
            $log->logErrorMsg("Error processing buttons: " . $e->getMessage());
            sleep(5);
            
            // Attempt to reconnect
            try {
                $pdo = getDatabaseConnection($dbConfig);
            } catch (Exception $e) {
                $log->logErrorMsg("Failed to reconnect: " . $e->getMessage());
            }
        }
    }
    
} catch (Exception $e) {
    $log->logErrorMsg("Fatal error: " . $e->getMessage());
    exit(1);
}