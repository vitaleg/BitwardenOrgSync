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
    private $lastCliError = '';
    private $duration = 0;
    public $notification;

    public function __construct(array $config) {
        $this->config = $config;

        $this->redis = Helper::redis($config);

        $this->notification = new Notification($config['NOTIFICAION'] ?? []);
    }

    private function setPid(string $server, int $pid) {
        $this->pids[$server] = $pid;
    }

    private function getPid(string $server) {
        return (isset($this->pids[$server])) ? $this->pids[$server] : false;
    }

    public function serverByPid(int $pid) {
        $server = array_search($pid, $this->pids, true);
        return ($server === false) ? null : $server;
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
                $this->exit('Fail to create session for ' . $server . (strlen($this->lastCliError) ? ': ' . $this->lastCliError : ''));
            }
        }
        $this->syncCli($server);
        $pid = pcntl_fork();
        if ($pid == -1) {
            $this->exit('Fail to start process ' . $server);
        } elseif ($pid === 0) {
            $cmd = $this->serve($server);
            $errMsg = "API Server $server just stopped: " . $this->describe($cmd);
            Logger::error($errMsg);
            $this->notification->send($errMsg);
            exit(1);
        }
        $this->setPid($server, $pid);
    }

    public function serve(string $server) {
        if ($this->isPortActive($this->config[$server]['SERVE_PORT'])) {
            $this->exit('Port ' . $this->config[$server]['SERVE_PORT'] . ' already used');
        }
        $cmd = $this->command($server, [], false);
        $cmd->addArg('serve')
            ->addArg('--port', $this->config[$server]['SERVE_PORT']);
        $cmd->execute();
        return $cmd;
    }

    public function syncCli(string $server) {
        $cmd = $this->command($server);
        $this->run($cmd->addArg('sync'));
        $this->checkCli("bw sync for $server", $cmd);
    }

    public function version() {
        $cmd = $this->command('BW_LOCAL');
        $this->run($cmd->addArg('--version'));
        return ($cmd->getExitCode() === 0) ? $cmd->getOutput() : false;
    }

    //The secrets (client secret, master password, session key) are passed in the environment of the process
    //and not in the command line, where they would be visible to everybody in the process list.
    private function command(string $server, array $env = [], bool $withTimeout = true) {
        $cmd = new Command(escapeshellarg($this->config['BWCLI']));
        $inherited = getenv();
        unset($inherited['BW_SESSION']); //a session of another vault exported in the shell must not leak into this one
        $cmd->procEnv = array_map('strval', array_merge($inherited, self::BASIC_ENV, $this->env($server), $env));
        if ($withTimeout) {
            $cmd->timeout = (int) ($this->config['CLI_TIMEOUT'] ?? 120);
        }
        return $cmd;
    }

    private function env(string $server) {
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
        return $env;
    }

    //Runs a command and remembers how long it took: a command killed by the timeout has no other explanation
    private function run(Command $cmd) {
        $started = microtime(true);
        $cmd->execute();
        $this->duration = microtime(true) - $started;
    }

    private function describe(Command $cmd) {
        $text = 'exit code ' . var_export($cmd->getExitCode(), true);
        //the library counts whole seconds, so the command may be killed slightly earlier than the timeout
        if ($cmd->timeout && $this->duration >= $cmd->timeout - 1) {
            $text .= ", timeout of {$cmd->timeout} sec reached";
        }
        $error = $cmd->getError();
        if (strlen($error)) {
            $text .= ', ' . (strlen($error) > 300 ? '...' . substr($error, -300) : $error);
        }
        return $text;
    }

    private function checkCli(string $what, Command $cmd) {
        if ($cmd->getExitCode() !== 0) {
            $this->lastCliError = $what . ': ' . $this->describe($cmd);
            Logger::warning($this->lastCliError);
        }
    }

    private function unlock(string $server) {
        $unlock = $this->command($server, ['MASTER_PASSWORD' => $this->config[$server]['MASTER_PASSWORD']]);
        $this->run($unlock->addArg('unlock')->addArg('--passwordenv', 'MASTER_PASSWORD')->addArg('--raw'));

        if ($unlock->getExitCode() === 0) {
            $this->redis->set($server . '_SESSION', $unlock->getOutput());
            return true;
        }
        $this->lastCliError = 'bw unlock: ' . $this->describe($unlock);
        return false;
    }

    public function createSession(string $server) {

        if ($this->unlock($server)) {
            return true;
        }

        $setServer = $this->command($server);
        $this->run($setServer->addArg('config')->addArg('server', $this->config[$server]['URL']));
        $this->checkCli("bw config server for $server", $setServer);

        $login = $this->command($server);
        $this->run($login->addArg('login')->addArg('--apikey'));
        $this->checkCli("bw login for $server", $login);

        if ($this->unlock($server)) {
            return true;
        }

        return false;
    }

    public function IsSessionActive(string $server) {
        $status = $this->command($server);
        $this->run($status->addArg('status'));
        $data = json_decode($status->getOutput(), true);
        if (isset($data['status']) && $data['status'] == 'unlocked') {
            return true;
        }
        return false;
    }

    private function isPortActive($port, $timeout = 3) {
        foreach (['::1' => AF_INET6, '127.0.0.1' => AF_INET] as $address => $family) {
            $socket = @socket_create($family, SOCK_STREAM, SOL_TCP);
            if ($socket === false) {
                continue;
            }
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $timeout, 'usec' => 0));
            $result = @socket_connect($socket, $address, $port);
            socket_close($socket);
            if ($result) {
                return true;
            }
        }
        return false;
    }

    public function exit(string $errMsg, bool $notify = true) {
        Logger::error($errMsg);
        if ($notify) {
            $this->notification->send($errMsg);
        }
        exit(1);
    }
}
