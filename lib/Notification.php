<?php
namespace BitwardenOrgSync;

use \BitwardenOrgSync\HttpClient;

class Notification {

    private $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    public function send(string $message) {
        return $this->telegram($message);
    }

    public function telegram(string $message) {
        if (!isset($this->config['TELEGRAM']['BOT_TOKEN'])) {
            return;
        }
        if (!isset($this->config['TELEGRAM']['CHAT_ID'])) {
            return;
        }
        $message = "BitwardenOrgSync\n\n" . $message;
        $client = new HttpClient;
        $query = $client->setUrl('https://api.telegram.org/bot' . $this->config['TELEGRAM']['BOT_TOKEN'] . '/sendMessage');
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
        return false;
    }

}
