<?php
namespace BitwardenOrgSync;

use mikehaertl\shellcommand\Command;

class BWSync {

    private const BASIC_ENV = [
        'OPENSSL_CONF' => '/dev/null'
    ];

    private $config;
    private $sessions = [];
    private $procs = [];
    private $logs = [];
    private $lastCliError = '';
    private $duration = 0;
    public $notification;

    public function __construct(array $config) {
        $this->config = $config;

        $this->notification = new Notification($config['NOTIFICAION'] ?? []);
    }

    public function serveBackground(string $server) {
        if (!$this->IsSessionActive($server)) {
            if (!$this->createSession($server)) {
                $this->exit('Fail to create session for ' . $server . (strlen($this->lastCliError) ? ': ' . $this->lastCliError : ''));
            }
        }
        $this->syncCli($server);
        $this->serve($server);
    }

    //Starts "bw serve" as a child process; its output goes to a file, so nothing piles up in the memory of this process
    public function serve(string $server) {
        $port = $this->config[$server]['SERVE_PORT'];
        if ($this->isPortActive($port)) {
            $this->exit('Port ' . $port . ' already used');
        }
        $cmd = $this->command($server, [], false);
        $cmd->addArg('serve')
            ->addArg('--port', $port);
        $log = sys_get_temp_dir() . '/bwsync-serve-' . $server . '.log';
        @file_put_contents($log, '');
        @chmod($log, 0600);
        //"exec" replaces the shell, so the process we stop later is bw itself
        $proc = proc_open('exec ' . $cmd->getExecCommand(), [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $cmd->procEnv);
        if (!is_resource($proc)) {
            $this->exit('Fail to start process ' . $server);
        }
        $this->procs[$server] = $proc;
        $this->logs[$server] = $log;
    }

    //Stops the script when one of the bw serve processes has died
    public function checkServers() {
        foreach ($this->procs as $server => $proc) {
            $status = proc_get_status($proc);
            if ($status['running']) {
                continue;
            }
            unset($this->procs[$server]);
            $reason = $status['signaled'] ? 'killed by signal ' . $status['termsig'] : 'exit code ' . $status['exitcode'];
            $tail = $this->logTail($this->logs[$server]);
            $this->exit("Error: $server API server stopped ($reason)" . (strlen($tail) ? ': ' . $tail : ''));
        }
    }

    public function stopServers() {
        foreach ($this->procs as $proc) {
            proc_terminate($proc);
        }
        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            $running = false;
            foreach ($this->procs as $proc) {
                $running = $running || proc_get_status($proc)['running'];
            }
            if (!$running) {
                break;
            }
            usleep(100000);
        }
        foreach ($this->procs as $proc) {
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc, 9);
            }
            proc_close($proc);
        }
        $this->procs = [];
    }

    private function logTail(string $file) {
        $size = (int) @filesize($file);
        $text = trim((string) @file_get_contents($file, false, null, max(0, $size - 500)));
        return preg_replace('/\s+/', ' ', $text);
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
        if (isset($this->sessions[$server])) {
            $env['BW_SESSION'] = $this->sessions[$server];
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
            $this->sessions[$server] = $unlock->getOutput(); //kept in memory only
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
        $this->stopServers();
        exit(1);
    }
}
