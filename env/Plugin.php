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

/**
 * Provides some utilities related to environments.
 */
class Plugin extends BasePlugin
{
    /**
     * @{inheritDoc}
     */
    public function appendConfiguration(ArrayNodeDefinition $rootNode)
    {
        $rootNode
            ->children()
                ->scalarNode('local_env')->end()
                ->arrayNode('envs')
                    ->prototype('array')
                        ->children()
                            ->scalarNode('ssh')->end()
                            ->scalarNode('root')
                                ->validate()
                                    ->ifTrue(
                                        function($v) {
                                            return preg_match('~[^/]$~', $v);
                                        }
                                    )
                                    ->then(
                                        function($v) {
                                            return $v . '/';
                                        }
                                    )
                                ->end()
                            ->end()
                            ->scalarNode('ssh_port')
                                ->defaultValue(22)
                            ->end()
                            ->scalarNode('web')->end()
                            ->scalarNode('url')->end()
                            ->scalarNode('db')->end()
                            ->scalarNode('host')->end()
                            ->scalarNode('db_defaults_file')
                                ->defaultValue(null)
                                ->beforeNormalization()
                                    ->ifString()
                                        ->then(function($v) { return sprintf(' --defaults-extra-file=%s ', $v); })
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->useAttributeAsKey('name')
                ->end()
            ->end()
        ;
    }


    public function setContainer(Container $container)
    {
        $container->method(array('ssh'), function($container, $env) {
            $envSsh = $container->resolve(array('envs', $env, 'ssh'), true);
            return sprintf('ssh ' . $envSsh . ' -tq "' . $container->resolve('SHELL') . '"');
        });
        $container->method(
            array('env', 'versionat'),
            function (Container $container, $env, $verbose = false) {
                static $envVcsInfo = array();

                if (!isset($envVcsInfo[$env])) {
                    $revFile = sprintf('%s/%s', rtrim($container->resolve(array('envs', $env, 'root'), true), '/'), $container->resolve(array('vcs', 'export', 'revfile'), true));
                    $envSsh = $container->resolve(array('envs', $env, 'ssh'), true);
                    $tmp = tempnam(sys_get_temp_dir(), 'z_rev_');
                    $cmd = sprintf('scp -o ConnectTimeout=7 %s:%s %s || true', $envSsh, $revFile, $tmp);
                    $container->helperExec($cmd);

                    $envVcsInfo[$env] = file_get_contents($tmp);
                    unlink($tmp);
                }

                if (!isset($envVcsInfo[$env]) || empty($envVcsInfo[$env])) {
                    $envVcsInfo[$env] = 'commit 0000000';
                }

                if ($verbose) {
                    return $envVcsInfo[$env];
                } else {
                    return $container->call($container->get(array('vcs', 'versionid')), $envVcsInfo[$env]);
                }
            }
        );
        $container->fn(
            array('ssh', 'connectable'),
            function ($ssh, $sshPort = null) {
                $sshOptions = '-o BatchMode=yes -o ConnectTimeout=1 -o StrictHostKeyChecking=no -Tq';
                if ($sshPort) {
                    $sshOptions .= ' -p ' . $sshPort;
                }
                $sshOptions .= ' ' . $ssh;

                return shell_exec(sprintf('ssh %s "echo 1" 2>/dev/null;', $sshOptions));
            }
        );
    }
}