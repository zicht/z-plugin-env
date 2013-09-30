<?php
/**
 * For licensing information, please see the LICENSE file accompanied with this file.
 *
 * @author Gerard van Helden <drm@melp.nl>
 * @copyright 2012 Gerard van Helden <http://melp.nl>
 */

namespace Zicht\Tool\Plugin\Env;

use \Zicht\Tool\Plugin as BasePlugin;
use \Zicht\Tool\Container\Container;
use \Symfony\Component\Process\Process;
use \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class Plugin extends BasePlugin
{

    /**
     * Configures the env parameters
     *
     * @param \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $rootNode
     * @return mixed|void
     */
    public function appendConfiguration(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->children()
                ->arrayNode('portforward')
                    ->children()
                        ->scalarNode('local')->end()
                        ->scalarNode('remote')->end()
                        ->scalarNode('host')->end()
                    ->end()
                ->end()
            ->end()
        ;
    }


    public function setContainer(Container $container)
    {

        $container->method('env.versionat', function(Container $container, $env, $verbose = false) {
            static $envVcsInfo = array();

            if (!isset($envVcsInfo[$env])) {
                $tmp = tempnam(sys_get_temp_dir(), 'z');
                $container->helperExec(sprintf(
                    'scp %s:%s/%s %s # (get remote version id)',
                    $container->resolve('env.ssh'),
                    $container->resolve('env.root'),
                    $container->resolve('vcs.export.revfile'),
                    $tmp
                ));
                $envVcsInfo[$env] = file_get_contents($tmp);
                unlink($tmp);
            }

            if ($verbose) {
                return $envVcsInfo[$env];
            } else {
                return $container->call('vcs.versionid', $envVcsInfo[$env]);
            }
        });

        $container->decl('env.ssh.connectable', function($container) {
            return shell_exec(sprintf('ssh -oBatchMode=yes %s "echo 1" 2>/dev/null;', $container->resolve('env.ssh')));
        });

        $self = $this;

        $container->decl('env.portforward', function(Container $container) use ($self) {


            $bindParam     = null;
            $remotePorts   = array();
            $localPorts    = array();
            $hosts         = array();
            $skipPortCheck = false;

            if ($container->has('port_remote')) {

                $remotePort = $container->resolve('port_remote');
                $host       = $container->resolve('host');

                if($container->has('port_local')){
                    $localPort  = $container->resolve('port_local');
                }else{
                    // none given set port to non privileged port < 1024
                    $localPort  = $container->resolve('port_remote') + 2000;
                }

                // check if port is used else check next one
                while ($self->checkLocalPort($localPort, $container) === false){
                    // Some debug info
                    if($container->has('verbose')){
                        $container->output->writeln(
                            sprintf(
                                "Port %s is used by system, trying next one",
                                $localPort
                            )
                        );
                    }

                    $localPort++;

                }
                // Some info
                if(!$container->has('explain')){
                    $container->output->writeln(
                        sprintf(
                            "Forwarding  %s:%s => %s:%s",
                            $host,
                            $remotePort,
                            trim(`hostname`),
                            $localPort
                        )
                    );
                }

                $bindParam .= sprintf('-L %s:%s:%s ', $localPort, $host, $remotePort);

            }else{

                if ($container->has('portforward.remote')) {
                    $remotePorts = $self->processPortString($container->resolve('portforward.remote'));
                }

                if ($container->has('portforward.local')) {
                    $localPorts = $self->processPortString($container->resolve('portforward.local'));

                    if(count($localPorts) !== count($remotePorts)){
                        throw new \InvalidArgumentException('Defined local and remote ports does not match, check settings');
                    }
                } else {
                    // we do the port check here so
                    // no need to check later.
                    $skipPortCheck = true;

                    foreach($remotePorts as $id => $remotePort){
                        // none given set port to non privileged port < 1024
                        // and check if port is used else check next one
                        $localPort = $remotePort + 2000;

                        while ($self->checkLocalPort($localPort, $container) === false){
                            $localPort++;
                        }

                        $localPorts[$id] = $localPort;
                    }
                }

                if ($container->has('portforward.host')) {

                    $hosts = array_map(
                        'trim',
                        explode(
                            ',',
                            $container->resolve('portforward.host')
                        )
                    );

                    if(count($hosts) !== count($remotePorts)){
                        throw new \InvalidArgumentException('Defined host(s) does not match defined remote port(s), check settings');
                    }
                } else {
                    foreach($remotePorts as $id => $remotePort){
                        $hosts[$id] = 'localhost';
                    }
                }

                if ( $skipPortCheck === false ){
                    foreach($localPorts as $id => $localPort){

                        if($container->has('verbose')){

                            $container->output->writeln(
                                sprintf(
                                    "Checking if system is listening to port %s",
                                    $localPort
                                )
                            );

                        }

                        if($self->checkLocalPort($localPort, $container) === false){
                            throw new \Symfony\Component\Process\Exception\RuntimeException(
                                sprintf(
                                    'System already listening to port %s, try using a different port',
                                    $localPort
                                )
                            );
                        }
                    }
                }

                foreach(array_keys($remotePorts) as $id){

                    // Some info
                    if(!$container->has('explain')){
                        $container->output->writeln(
                            sprintf(
                                "Forwarding  %s:%s => %s:%s",
                                $hosts[$id],
                                $remotePorts[$id],
                                trim(`hostname`),
                                $localPorts[$id]
                            )
                        );
                    }

                    $bindParam .= sprintf('-L %s:%s:%s ', $localPorts[$id], $hosts[$id], $remotePorts[$id]);
                }
            }



            return $bindParam;

        });


    }

    /**
     * Check if given port is used
     *
     * On match ( and port is used ) it will
     * return something like :
     * tcp6  0  0 :::80 :::* LISTEN
     * So we need to check for empty string
     *
     * @param $port
     * @param \Zicht\Tool\Container\Container $container
     * @return bool
     * @throws \UnexpectedValueException
     */
    public function checkLocalPort($port, Container $container){

        $return = false;
        $cmd    = sprintf(
            "netstat -lnt | awk '$6 == \"LISTEN\" && $4 ~ \".%s\"'",
            $port
        );


        if ($container->resolve('explain')) {

            $container->output->writeln(sprintf('# Check if port %s is used',$port));

            $container->output->writeln(
                sprintf(
                    '( %s );',
                    rtrim(
                        trim($cmd),
                        "\n"
                    )
                )
            );

            $return = true;


        } else {

            $process    = new Process($cmd);

            $process->run();

            $exitCode = $process->getExitCode();
            $stdOut   = $process->getOutput();

            if($exitCode > 0){
                throw new \UnexpectedValueException(
                    sprintf(
                        "Checking if port was used failed [%s] %s",
                        $exitCode,
                        $process->getErrorOutput()
                    )
                );
            }

            $return = empty($stdOut);
        }



        return $return;
    }

    /**
     * will check if given var is numeric, and splits given string on comma
     *
     * @param $ports
     * @return array
     * @throws \UnexpectedValueException
     */
    public function processPortString($ports){

        $ports = explode(',', $ports);

        array_walk($ports, function(&$v){

                if(\is_numeric($v)){
                    $v = (int) trim($v);
                }else{
                    throw new \UnexpectedValueException(
                        sprintf(
                            '%s is not a valid port',
                            $v
                        )
                    );
                }

            }

        );
        return $ports;
    }
}