<?php

namespace Aegir\Provision\Context;

use Aegir\Provision\ContextProvider;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Class ServerContext
 *
 * @package Aegir\Provision\Context
 *
 * @see \Provision_Context_server
 */
class ServerContext extends ContextProvider implements ConfigurationInterface
{
    /**
     * @var string
     * 'server', 'platform', or 'site'.
     */
    public $type = 'server';
    const TYPE = 'server';


    static function option_documentation()
    {
        $options = [
          'remote_host' => 'server: host name; default localhost',
          'script_user' => 'server: OS user name; default current user',
          'aegir_root' => 'server: Aegir root; default '.getenv('HOME'),
          'master_url' => 'server: Hostmaster URL',
        ];

        return $options;
    }


    /**
     * Run a shell command on this server.
     *
     * @TODO: Run remote commands correctly.
     *
     * @param $cmd
     * @return string
     * @throws \Exception
     */
    public function shell_exec($cmd) {
        $output = '';
        $exit = 0;
        exec($cmd, $output, $exit);

        if ($exit != 0) {
            throw new \Exception("Command failed: $cmd");
        }

        return implode("\n", $output);
    }
}
