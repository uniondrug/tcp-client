<?php

namespace Uniondrug\TcpClient;

use Phalcon\Di\ServiceProviderInterface;

class TcpClientServiceProvider implements ServiceProviderInterface
{
    public function register(\Phalcon\DiInterface $di)
    {
        $di->setShared(
            'tcpClient',
            function () {
                return new Client();
            }
        );
    }
}
