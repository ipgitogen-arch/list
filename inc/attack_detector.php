<?php
// Файл: /inc/attack_detector.php

class AttackDetector {
    private $pdo;
    private $ip;
    private $userAgent;
    
    // Список подозрительных путей
    private $suspiciousPaths = [
        'shell.php', 'webshell.php', 'cmd.php', 'backdoor.php',
        'wp-admin', 'wp-login.php', 'xmlrpc.php', 'wp-config.php',
        '.env', '.git', 'config.php', 'phpinfo.php', 'info.php',
        'admin.php', 'login.php', 'setup.php', 'install.php',
        'webshell', 'shell', 'cmd', 'backdoor', 'hack', 'exploit',
        'phpmyadmin', 'mysql', 'phpinfo', 'whoami', 'passwd',
        'etc/passwd', 'etc/shadow', 'proc/self/environ'
    ];
    
    // Список подозрительных User-Agent
    private $suspiciousUA = [
        'palo alto', 'scanning', 'headless', 'bot', 'crawler',
        'zgrab', 'httpx', 'masscan', 'nmap', 'sqlmap', 'nikto'
    ];
    
    // Список подозрительных параметров
    private $suspiciousParams = [
        'cmd', 'exec', 'system', 'shell_exec', 'passthru',
        'eval', 'assert', 'system', 'file_get_contents',
        'fopen', 'include', 'require', 'base64_decode'
    ];
    
    public function __construct($pdo, $ip, $userAgent = '') {
        $this->pdo = $pdo;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
    }
    
    public function detect($requestUri, $requestMethod, $responseCode) {
        $attackType = null;
        $fileAttempted = null;
        $isMalicious = false;
        
        // Проверяем подозрительные пути
        foreach ($this->suspiciousPaths as $path) {
            if (stripos($requestUri, $path) !== false) {
                $attackType = 'Подозрительный файл/путь';
                $fileAttempted = $path;
                $isMalicious = true;
                break;
            }
        }
        
        // Проверяем расширения файлов
        $badExtensions = ['.php.bak', '.php~', '.sql', '.bak', '.old', '.backup'];
        foreach ($badExtensions as $ext) {
            if (stripos($requestUri, $ext) !== false) {
                $attackType = 'Попытка доступа к бэкапу';
                $fileAttempted = basename($requestUri);
                $isMalicious = true;
                break;
            }
        }
        
        // Проверяем подозрительный User-Agent
        foreach ($this->suspiciousUA as $ua) {
            if (stripos($this->userAgent, $ua) !== false) {
                if (!$attackType) $attackType = 'Подозрительный User-Agent';
                $isMalicious = true;
                break;
            }
        }
        
        // Проверяем метод запроса (необычные методы)
        $badMethods = ['TRACE', 'TRACK', 'OPTIONS', 'DELETE', 'PUT'];
        if (in_array($requestMethod, $badMethods)) {
            $attackType = 'Нестандартный HTTP метод: ' . $requestMethod;
            $isMalicious = true;
        }
        
        // Проверяем код ответа (403 или 404 при подозрительном URI)
        if (($responseCode == 403 || $responseCode == 404) && $isMalicious) {
            $attackType = $attackType ?: 'Доступ запрещен/не найден';
            $isMalicious = true;
        }
        
        if ($isMalicious) {
            $this->saveAttack($requestUri, $requestMethod, $attackType, $fileAttempted, $responseCode);
            $this->checkAndBlock($requestUri);
        }
        
        return $isMalicious;
    }
    
    private function saveAttack($requestUri, $requestMethod, $attackType, $fileAttempted, $responseCode) {
        // Получаем геоданные
        $geoData = $this->getGeoData();
        
        $stmt = $this->pdo->prepare("
            INSERT INTO attack_logs (ip, user_agent, request_method, request_uri, attack_type, file_attempted, response_code, country, city, isp, asn, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $this->ip,
            $this->userAgent,
            $requestMethod,
            $requestUri,
            $attackType,
            $fileAttempted,
            $responseCode,
            $geoData['country'],
            $geoData['city'],
            $geoData['isp'],
            $geoData['asn']
        ]);
    }
    
    private function getGeoData() {
        // Используем ip-api.com (бесплатно, без ключа)
        $url = "http://ip-api.com/json/{$this->ip}?fields=status,country,city,isp,as";
        
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $data = json_decode($response, true);
            if ($data && $data['status'] == 'success') {
                return [
                    'country' => $data['country'] ?? 'Unknown',
                    'city' => $data['city'] ?? 'Unknown',
                    'isp' => $data['isp'] ?? 'Unknown',
                    'asn' => $data['as'] ?? 'Unknown'
                ];
            }
        } catch (Exception $e) {}
        
        return ['country' => null, 'city' => null, 'isp' => null, 'asn' => null];
    }
    
    private function checkAndBlock($requestUri) {
        // Считаем количество атак с этого IP за последние 5 минут
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM attack_logs 
            WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute([$this->ip]);
        $attackCount = $stmt->fetchColumn();
        
        // Если более 5 атак за 5 минут - блокируем
        if ($attackCount > 5) {
            $this->addToFirewall();
        }
    }
    
    private function addToFirewall() {
        // Добавляем в .htaccess
        $htaccessPath = __DIR__ . '/../.htaccess';
        $blockRule = "\n# Блокировка злоумышленника " . date('Y-m-d H:i:s') . "\n";
        $blockRule .= "Require ip not " . $this->ip . "\n";
        
        if (file_exists($htaccessPath)) {
            $content = file_get_contents($htaccessPath);
            if (strpos($content, "Require ip not " . $this->ip) === false) {
                file_put_contents($htaccessPath, $blockRule, FILE_APPEND);
            }
        }
        
        // Помечаем в БД
        $stmt = $this->pdo->prepare("UPDATE attack_logs SET blocked = 1 WHERE ip = ?");
        $stmt->execute([$this->ip]);
    }
    
    public static function getActiveAttacks($pdo, $limit = 100) {
        $stmt = $pdo->prepare("
            SELECT *, 
                   TIMESTAMPDIFF(MINUTE, created_at, NOW()) as minutes_ago
            FROM attack_logs 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    public static function getAttackStats($pdo) {
        $stats = [];
        
        // Общее количество атак
        $stmt = $pdo->query("SELECT COUNT(*) FROM attack_logs");
        $stats['total'] = $stmt->fetchColumn();
        
        // Уникальные IP
        $stmt = $pdo->query("SELECT COUNT(DISTINCT ip) FROM attack_logs");
        $stats['unique_ips'] = $stmt->fetchColumn();
        
        // Атаки за последние 24 часа
        $stmt = $pdo->query("SELECT COUNT(*) FROM attack_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stats['last_24h'] = $stmt->fetchColumn();
        
        // Топ стран
        $stmt = $pdo->query("
            SELECT country, COUNT(*) as count 
            FROM attack_logs 
            WHERE country IS NOT NULL 
            GROUP BY country 
            ORDER BY count DESC 
            LIMIT 10
        ");
        $stats['top_countries'] = $stmt->fetchAll();
        
        // Топ атак
        $stmt = $pdo->query("
            SELECT attack_type, COUNT(*) as count 
            FROM attack_logs 
            WHERE attack_type IS NOT NULL 
            GROUP BY attack_type 
            ORDER BY count DESC 
            LIMIT 10
        ");
        $stats['top_attacks'] = $stmt->fetchAll();
        
        return $stats;
    }
}
?>