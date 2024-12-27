<?php
// hue_lib.php

class HueCommandQueue {
    private $pdo;
    
    public function __construct($dbConfig) {
        $this->pdo = new PDO(
            "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
            $dbConfig['user'],
            $dbConfig['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    
    public function getNextBatch($limit = 5) {
        $this->pdo->beginTransaction();
        try {
            // Add timeout check - reset commands stuck processing for >5 minutes
            $stmt = $this->pdo->prepare("
                UPDATE command_queue 
                SET status = 'pending',
                    processed_at = NULL
                WHERE status = 'processing' 
                AND processed_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            $stmt->execute();
    
            // Get next batch of pending Hue commands
            $stmt = $this->pdo->prepare("
                SELECT id, device, model, command 
                FROM command_queue
                WHERE status = 'pending'
                AND brand = 'hue'
                ORDER BY created_at ASC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark these commands as processing
            if (!empty($commands)) {
                $ids = array_column($commands, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $this->pdo->prepare("
                    UPDATE command_queue
                    SET status = 'processing',
                        processed_at = CURRENT_TIMESTAMP
                    WHERE id IN ($placeholders)
                ");
                $stmt->execute($ids);
            }
            
            $this->pdo->commit();
            return $commands;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    public function markCommandComplete($id, $success = true, $errorMessage = null) {
        $stmt = $this->pdo->prepare("
            UPDATE command_queue
            SET 
                status = :status,
                processed_at = CURRENT_TIMESTAMP,
                error_message = :error_message
            WHERE id = :id
        ");
        
        $stmt->execute([
            'status' => $success ? 'completed' : 'failed',
            'error_message' => $errorMessage,
            'id' => $id
        ]);
    }
}

class HueAPI {
    private $bridgeIP;
    private $apiKey;
    private $commandQueue;
    
    public function __construct($bridgeIP, $apiKey, $dbConfig = null) {
        $this->bridgeIP = $bridgeIP;
        $this->apiKey = $apiKey;
        if ($dbConfig) {
            $this->commandQueue = new HueCommandQueue($dbConfig);
        }
    }
    
    public function processBatch($maxCommands = 5) {
        $commands = $this->commandQueue->getNextBatch($maxCommands);
        $results = [];
        
        foreach ($commands as $command) {
            try {
                // Send the command
                $result = $this->sendCommand(
                    $command['device'],
                    json_decode($command['command'], true)
                );
                
                $this->commandQueue->markCommandComplete($command['id'], true);
                $results[] = [
                    'command_id' => $command['id'],
                    'result' => $result,
                    'success' => true
                ];
                
            } catch (Exception $e) {
                $this->commandQueue->markCommandComplete(
                    $command['id'],
                    false,
                    $e->getMessage()
                );
                $results[] = [
                    'command_id' => $command['id'],
                    'error' => $e->getMessage(),
                    'success' => false
                ];
            }
        }
        
        return [
            'success' => true,
            'processed' => count($results),
            'results' => $results
        ];
    }
    
    public function sendCommand($device, $cmd) {
    // Validate basic parameters
    if (!$device) {
        throw new Exception('Device ID is required');
    }
    
    // Validate command structure
    if (!is_array($cmd) || !isset($cmd['name'])) {
        throw new Exception('Invalid command format');
    }
    
    // Transform command based on type
    $hueCmd = [];
    
    // Handle different command types
    switch ($cmd['name']) {
        case 'brightness':
            if (!isset($cmd['value']) || !is_numeric($cmd['value'])) {
                throw new Exception('Brightness value must be a number');
            }
            // When setting brightness, include both on state and brightness
            $hueCmd = [
                'on' => [
                    'on' => true
                ],
                'dimming' => [
                    'brightness' => (int)$cmd['value']
                ]
            ];
            break;
            
        case 'turn':
            if (!isset($cmd['value']) || !in_array($cmd['value'], ['on', 'off'])) {
                throw new Exception('Turn command must specify "on" or "off"');
            }
            // When turning off, only include on state
            $hueCmd = [
                'on' => [
                    'on' => ($cmd['value'] === 'on')
                ]
            ];
            break;
            
        default:
            throw new Exception('Unsupported command type: ' . $cmd['name']);
    }

    // Send command to Hue Bridge
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://{$this->bridgeIP}/clip/v2/resource/light/{$device}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_HTTPHEADER => array(
            'hue-application-key: ' . $this->apiKey,
            'Content-Type: application/json'
        ),
        CURLOPT_POSTFIELDS => json_encode($hueCmd)
    ));

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpCode !== 200) {
        throw new Exception("Failed to communicate with Hue bridge (HTTP $httpCode): $response");
    }
    
    $result = json_decode($response, true);
    
    // Check for Hue API errors
    if (is_array($result) && isset($result[0]['error'])) {
        throw new Exception($result[0]['error']['description']);
    }
    
    return [
        'success' => true,
        'message' => 'Command sent successfully'
    ];
}
}