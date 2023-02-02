<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class SshCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'ssh';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return mixed
     */

    protected $home;
    protected $config;
    protected $connections;
    public function handle()
    {
        $this->init();
        $command = $this->menu('Commands', [
            'Connect',
            'List All Connection',
            'Register New Connection',
            'Delete Connection',
        ])->open();
        switch ($command) {
            case 0:
                $this->connectConnection();
                break;
            case 1:
                $this->listConnection();
                break;
            case 2:
                $this->registerConnection();
                break;
            case 3:
                $this->deleteConnection();
                break;
            default:
                break;
        }
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
    }

    function init()
    {
        $this->home = getenv('HOME') . '/.connect';
        $this->config = $this->home . '/connect.json';
        if (!is_dir($this->home . '/keys')) {
            mkdir($this->home  . '/keys', 0775, true);
        }
        if (!is_file($this->config)) {
            fopen($this->config, 'w');
        }
        $this->connections = json_decode(file_get_contents($this->config), true) ?? [];
        return;
    }
    private function connectConnection()
    {
        $names = [];
        foreach ($this->connections as $k => $connection) {
            $name = $connection['name'];
            if( $connection['port_forwarding'] === 'yes'){
                $name .= ' [Port Forwarding]';
            }
            $names[$k] = $name;
        }
        $connect = $this->menu('All Connections', $names)->open();
        if( !is_int($connect) ) {
            return;
        }
        $connect = $this->connections[$connect];
        $this->connect($connect);
        return;
    }
    private function listConnection()
    {
        $names = [];
        foreach ($this->connections as $k => $connection) {
            $name = $connection['name'];
            if( $connection['port_forwarding'] === 'yes'){
                $name .= ' [Port Forwarding]';
            }
            $names[$k] = $name;
        }
        $connect = $this->menu('All Connections', $names)->open();
        $connect = $this->connections[$connect];
        
        $action = $this->menu('Action', [
            'Info',
            'Connect'
        ])->open();

        if($action === 0) {
            $this->info('Connection Info:');
            $this->line('Name: ' . $connect['name']);
            $this->line('Host: ' . $connect['host']);
            $this->line('Port: ' . $connect['port']);
            if( $connection['port_forwarding'] === 'yes'){
                $this->line('Port Forwarding');
                $this->line('Host: '. $connect['host']);
                $this->line('Method: '. $connect['pf_method']);
                $this->line('Local: '. $connect['local_port']);
                $this->line('Remote: '. $connect['remote_port']);
            }
        }
        if($action === 1) {
            $this->connect($connect);
        }

        return;
    }
    private function deleteConnection()
    {
        $names = [];
        foreach ($this->connections as $k => $connection) {
            $names[$k] = $connection['name'];
        }
        $connect = $this->menu('All Connections', $names)->open();
        if(is_int($connect)) {
            $selected = $this->connections[$connect];
            if( $selected['type'] === 'public_key') {
                try{unlink($selected['private_key']);} catch(\Exception $e){}
            }
            unset($this->connections[$connect]);
            file_put_contents($this->config, json_encode($this->connections));
        }
        $this->handle();
        return;
    }
    private function registerConnection()
    {
        $this->info('Register New Connection');
        $conn = [];
        $conn['name'] = $this->ask('name');
        $conn['slug'] = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $conn['name'])));
        foreach ($this->connections as $cn) {
            if ($cn['slug'] === $conn['slug']) {
                $this->error('Connection with same name has been found');
                return;
            }
        }
        $conn['host'] = $this->ask('host', '127.0.0.1');
        $conn['port'] = $this->ask('port', 22);
        $conn['user'] = $this->ask('user', 'root');
        $this->info('Authentication Type: public_key / password');
        $conn['type'] = $this->ask('type', 'public_key');
        if (strtolower($conn['type']) === 'public_key') {
            $keys = scandir(getenv('HOME') . '/.ssh/');
            $matchedKeys = [];
            foreach ($keys as $key) {
                if ($key !== '.' && $key !== '..' && substr($key, -4) === '.pub') {
                    $matchedKeys[] = $key;
                }
            }
            $selectedKey = count($matchedKeys) > 0 ? (getenv('HOME') . '/.ssh/' . substr($matchedKeys[0], 0, -4)) : '';
            $conn['private_key'] = $this->ask('private_key', $selectedKey);
            if (!preg_match('/^\S.*\s.*\S$/', $conn['private_key'])) {
                $conn['private_key'] = file_get_contents($selectedKey);
            }
            $privKey = $conn['slug'] . '.key';
            if (!is_file($this->home . '/keys/' . $privKey)) {
                fopen($this->home . '/keys/' . $privKey, 'w');
            }
            file_put_contents($this->home . '/keys/' . $privKey, $conn['private_key']);
            chmod($this->home . '/keys/' . $privKey, 0600);
            $conn['private_key'] = $this->home . '/keys/' . $privKey;
        }
        $this->info('Password: user password / key phasprase');
        $conn['password'] = $this->ask('password', null);
        $conn['port_forwarding'] = strtolower($this->ask('port_forwarding', 'no'));
        if( $conn['port_forwarding'] === 'yes' ) {
            $this->info('Port Forwarding Method: local / remote');
            $conn['pf_method'] = strtolower($this->ask('pf_method', 'local'));
            $conn['local_port'] = $this->ask('local_port', 8088);
            $conn['remote_port'] = $this->ask('remote_port', 80);
        }
        $this->connections[] = $conn;
        file_put_contents($this->config, json_encode($this->connections));

        $this->info(sprintf('Connection %s saved', $conn['name']));
        return;
    }

    private function connect($config) {
        $forwarding = '';
        if($config['port_forwarding']==='yes') {
            $pfMethod = ($config['pf_method']==='local')?'L':'R';
            $pfHost = ($config['pf_method']==='local')?'127.0.0.1':$config['host'];
            $forwarding = sprintf('-%s %s:%s:%s',$pfMethod, $config['local_port'], $pfHost, $config['remote_port']);
        }
        if ($config['type'] === 'public_key') {
            passthru(sprintf(
                'ssh -o StrictHostKeyChecking=no -i %s %s@%s -p %s %s',
                $config['private_key'],
                $config['user'],
                $config['host'],
                $config['port'],
                $forwarding
            ));
        } else {
            passthru(sprintf(
                'sshpass -p %s ssh -o StrictHostKeyChecking=no %s@%s -p %s %s',
                $config['password'],
                $config['user'],
                $config['host'],
                $config['port'],
                $forwarding
            ));
        }
        return;
    }
}
