<?php
namespace BitwardenOrgSync;

use \BitwardenOrgSync\HttpClient;

class Notification {

    private const DEFAULT_THROTTLE = 3600;

    private $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    public function send(string $message) {
        if (!$this->isConfigured()) {
            return false;
        }
        if ($this->isThrottled($message)) {
            Logger::debug('Notification suppressed, the same one was sent recently: ' . $message);
            return false;
        }
        if ($this->telegram($message)) {
            $this->markSent($message);
            return true;
        }
        return false;
    }

    public function telegram(string $message) {
        if (!$this->isConfigured()) {
            return;
        }
        $message = "BitwardenOrgSync\n\n" . htmlspecialchars($message, ENT_NOQUOTES);
        $client = new HttpClient;
        $query = $client->setUrl($this->apiUrl() . '/bot' . $this->config['TELEGRAM']['BOT_TOKEN'] . '/sendMessage');
        $query->setHeaders(['Content-Type: application/json']);
        $query->setPost(true)->setPostFields([
            'chat_id' => $this->config['TELEGRAM']['CHAT_ID'],
            'text' => $message,
            'parse_mode' => 'html'
        ], true);
        $query->exec();
        if ($query->getStatusCode() === 200) {
            return true;
        }
        $response = $query->jsonToArray();
        $details = (is_array($response) && isset($response['description']) && is_string($response['description'])) ? $response['description'] : $query->getError();
        Logger::warning('Notification failed: HTTP ' . $query->getStatusCode() . (strlen($details) ? ': ' . $details : ''));
        return false;
    }

    private function isConfigured() {
        return !empty($this->config['TELEGRAM']['BOT_TOKEN']) && !empty($this->config['TELEGRAM']['CHAT_ID']);
    }

    private function apiUrl() {
        return rtrim($this->config['TELEGRAM']['API_URL'] ?? 'https://api.telegram.org', '/');
    }

    //The same message (digits are ignored, so PIDs and counters do not matter) is not sent more often than once per THROTTLE seconds.
    //A file is used to survive restarts of the process (docker "restart: always" would repeat the message every few seconds).
    private function throttleFile(string $message) {
        $key = md5(($this->config['TELEGRAM']['CHAT_ID'] ?? '') . '|' . preg_replace('/\d+/', '#', $message));
        return sys_get_temp_dir() . '/bwsync-notify-' . $key;
    }

    private function isThrottled(string $message) {
        $ttl = (int) ($this->config['THROTTLE'] ?? self::DEFAULT_THROTTLE);
        if ($ttl <= 0) {
            return false;
        }
        $file = $this->throttleFile($message);
        clearstatcache(true, $file);
        return is_file($file) && (time() - filemtime($file)) < $ttl;
    }

    private function markSent(string $message) {
        @touch($this->throttleFile($message));
    }

}
