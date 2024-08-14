<?php
namespace BitwardenOrgSync;

use mikehaertl\shellcommand\Command;

class BWSync {

    private const BASIC_ENV = [
        'OPENSSL_CONF' => '/dev/null'
    ];

    private $redis;
    private $config;
    private $pids = [];
    public $notification;

    public function __construct(array $config) {
        $this->config = $config;

        $this->redis = new \Redis();

        $this->redis->connect($config['REDIS']['ADDRESS'], $config['REDIS']['PORT']);
        if ($config['REDIS']['USERNAME']) {
            $this->redis->auth([$config['REDIS']['USERNAME'], $config['REDIS']['PASSWORD']]);
        } else if ($config['REDIS']['PASSWORD']) {
            $this->redis->auth($config['REDIS']['PASSWORD']);
        }
        if ($config['REDIS']['DATABASE_ID'] > 0) {
            $this->redis->swapdb($config['REDIS']['DATABASE_ID']);
        }
        $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

        $this->notification = new Notification($config['NOTIFICAION']);
    }

    private function setPid(string $server, int $pid) {
        $this->pids[$server] = $pid;
    }

    private function getPid(string $server) {
        return (isset($this->pids[$server])) ? $this->pids[$server] : false;
    }

    public function isProcessRunning(string $server) {
        if ($pid = $this->getPid($server)) {
            return file_exists('/proc/'. $pid);
        }
        return false;
    }

    public function serveBackground(string $server) {
        if (!$this->IsSessionActive($server)) {
            if (!$this->createSession($server)) {
                $this->exit('Fail to create session for ' . $server);
            }
        }
        $this->syncCli($server);
        $pid = pcntl_fork();
        $this->setPid($server, $pid);
        if ($pid == -1) {
            $this->exit('Fail to start process ' . $server);
        } elseif ($pid === 0) {
            $this->serve($server);
            $errMsg = "API Server $server just stopped";
            $this->notification->send($errMsg);
            exit(0);
        }
    }

    public function serve(string $server) {
        if ($this->isPortActive($this->config[$server]['SERVE_PORT'])) {
            $this->exit('Port ' . $this->config[$server]['SERVE_PORT'] . ' already used');
        }
        $cmd = new Command($this->cli($server));
        $cmd->addArg('serve')
            ->addArg('--port', $this->config[$server]['SERVE_PORT'])
            ->addArg('--disable-origin-protection');
        $cmd->execute();
    }

    public function syncCli(string $server) {
        $cmd = new Command($this->cli($server));
        $cmd->addArg('sync')->execute();
    }

    private function basicCli() {
        return $this->buildEnvString(self::BASIC_ENV) . ' ' . $this->config['BWCLI'];
    }

    private function cli(string $server) {
        $env = [
            'BITWARDENCLI_APPDATA_DIR' => $this->config[$server]['APPDATA_DIR'],
            'BW_CLIENTID' => $this->config[$server]['CLIENT_ID'],
            'BW_CLIENTSECRET' => $this->config[$server]['CLIENT_SECRET']
        ];
        if ($this->config[$server]['NODE_EXTRA_CA_CERTS']) {
            $env['NODE_EXTRA_CA_CERTS'] = $this->config[$server]['NODE_EXTRA_CA_CERTS'];
        }
        if ($sessionId = $this->redis->get($server . '_SESSION')) {
            $env['BW_SESSION'] = $sessionId;
        }
        return  $this->buildEnvString($env) . ' ' . $this->basicCli();
    }

    private function unlock(string $server) {
        $unlock = new Command('MASTER_PASSWORD=' . $this->config[$server]['MASTER_PASSWORD']. ' ' . $this->cli($server));
        $unlock->addArg('unlock')->addArg('--passwordenv', 'MASTER_PASSWORD')->addArg('--raw')->execute();

        if ($unlock->getExitCode() === 0) {
            $this->redis->set($server . '_SESSION', $unlock->getOutput());
            return true;
        }
        return false;
    }

    public function createSession(string $server) {

        if ($this->unlock($server)) {
            return true;
        }

        $setServer = new Command($this->cli($server));
        $setServer->addArg('config')->addArg('server', $this->config[$server]['URL'])->execute();

        $login = new Command($this->cli($server));
        $login->addArg('login')->addArg('--apikey')->execute();

        if ($this->unlock($server)) {
            return true;
        }

        return false;
    }

    public function IsSessionActive(string $server) {
        $status = new Command($this->cli($server));
        $status->addArg('status')->execute();
        $data = json_decode($status->getOutput(), true);
        if (isset($data['status']) && $data['status'] == 'unlocked') {
            return true;
        }
        return false;
    }

    private function isPortActive($port, $timeout = 3) {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return false;
        }
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $timeout, 'usec' => 0));
        $result = @socket_connect($socket, '::1', $port);
        if ($result) {
            socket_close($socket);
            return true;
        } else {
            socket_close($socket);
            return false;
        }
    }

    private function buildEnvString(array $env) {
        $final = [];
        foreach ($env as $key => $value) {
            $final[] = $key . '=' . $value;
        }
        return implode(' ', $final);
    }

    public function exit(string $errMsg) {
        $this->notification->send($errMsg);
        die($errMsg . "\n");
    }
}
