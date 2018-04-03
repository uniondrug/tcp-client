<?php
/**
 * 基于Swoole的Client封装，实现通过TCP发起Uniondrug的服务请求。兼容Guzzle的接口。
 */

namespace Uniondrug\TcpClient;

use Phalcon\Http\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Uniondrug\Server\Servitization\Client\Client as SwooleClient;

/**
 * Class Client
 *
 * @method ResponseInterface get(string $uri, array $options = [])
 * @method ResponseInterface head(string $uri, array $options = [])
 * @method ResponseInterface put(string $uri, array $options = [])
 * @method ResponseInterface post(string $uri, array $options = [])
 * @method ResponseInterface patch(string $uri, array $options = [])
 * @method ResponseInterface delete(string $uri, array $options = [])
 */
class Client
{
    /**
     * 连接管理器
     *
     * @var SwooleClient[]
     */
    protected $clients = [];

    /**
     * 超时时间
     *
     * @var int|mixed
     */
    protected $timeout = 30;

    /**
     * Client constructor.
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        if (isset($config['timeout'])) {
            $this->timeout = $config['timeout'];
        }
    }

    /**
     * @param $method
     * @param $args
     *
     * @return null
     * @throws \Exception
     */
    public function __call($method, $args)
    {
        if (count($args) < 1) {
            throw new \InvalidArgumentException('Magic request methods require a URI and optional options array');
        }

        $uri = $args[0];
        $opts = isset($args[1]) ? $args[1] : [];

        return $this->request($method, $uri, $opts);
    }

    /**
     * @param        $method
     * @param string $uri
     * @param array  $options
     *
     * @return ResponseInterface
     * @throws \Exception
     */
    public function request($method, $uri = '', array $options = [])
    {
        // 1. 提取当前的Trace信息，并且附加在请求头中
        /* @var RequestInterface $request */
        $request = app()->getShared('request');
        $traceId = $request->getHeader('X-TRACE-ID');
        if ($traceId) {
            $options['headers']['X-TRACE-ID'] = $traceId;
        } else {
            $traceId = '';
        }

        $spanId = $request->getHeader('X-SPAN-ID');
        if ($spanId) {
            $options['headers']['X-SPAN-ID'] = $spanId;
        } else {
            $spanId = '';
        }

        // 2. 发起请求
        $sTime = microtime(1);
        $exception = null;
        $error = '';
        $result = null;
        try {
            // 解析请求。$options保持与Guzzle基本兼容
            $info = parse_url($uri);
            $scheme = isset($info['scheme']) ? $info['scheme'] : 'http';
            $host = $info['host'];
            $port = isset($info['port']) ? $info['port'] : 80;
            $server = $scheme . '://' . $host . ':' . $port;

            $path = '/';
            if (isset($info['path'])) {
                $path = $info['path'];
            }
            if (isset($info['query'])) {
                $path = $path . '?' . $info['query'];
            }
            if (isset($options['query'])) {
                $path = $path . (false === strpos($path, '?') ? '?' : '&') . http_build_query($options['query']);
            }

            echo "$method, $uri, $path\n";

            $headers = [];
            if (isset($options['headers'])) {
                $headers = $options['headers'];
            }

            $timeout = $this->timeout;
            if (isset($options['timeout'])) {
                $timeout = $options['timeout'];
            }

            $data = [];
            if (isset($options['json'])) {
                $data = $options['json'];
            } elseif (isset($options['form_params'])) {
                $data = $options['form_params'];
            }

            $result = $this->getConnection($server)
                ->setPath($path)
                ->setMethod(strtoupper($method))
                ->setHeaders($headers)
                ->setTimeout($timeout)
                ->send($data);
            if ($info['scheme'] === 'http') {
                $this->close($server);
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
            $exception = $e;
        }
        $rTime = microtime(1);

        // 3. 从响应结果中获取子节点的SPAN_ID
        $childSpanId = '';
        if (null === $exception && null !== $result && ($result instanceof ResponseInterface)) {
            $childSpanId = $result->getHeader('X-SPAN-ID');
            if ($childSpanId) {
                $childSpanId = implode('; ', $childSpanId);
            }
        }

        // 4. 计算时间
        $time = $rTime - $sTime;

        // 5. LOG
        app()->getLogger('trace')->debug(sprintf("[TCPClient] traceId=%s, spanId=%s, childSpanId=%s, ss=%s, sr=%s, t=%s, uri=%s, error=%s",
            $traceId, $spanId, $childSpanId, $sTime, $rTime, $time, $uri, $error
        ));

        // 6. 发送到中心
        try {
            if (app()->has('traceClient')) {
                app()->getShared('traceClient')->send([
                    'traceId'     => $traceId,
                    'childSpanId' => $childSpanId,
                    'spanId'      => $spanId,
                    'timestamp'   => $sTime,
                    'duration'    => $time,
                    'cs'          => $sTime,
                    'cr'          => $rTime,
                    'uri'         => $uri,
                    'error'       => $error,
                ]);
            }
        } catch (\Exception $e) {
            app()->getLogger('trace')->error(sprintf("[TCPClient] Send to trace server failed: %s", $e->getMessage()));
        }

        // 7. 返回结果
        if ($exception !== null) {
            throw $exception;
        } else {
            return $result;
        }
    }

    /**
     * 返回一个连接
     *
     * @param $server
     *
     * @return \Uniondrug\Server\Servitization\Client\Client
     */
    public function getConnection($server)
    {
        if (!isset($this->clients[$server])) {
            $keep = !function_exists('app');
            $sync = false; // 只使用同步模式
            $this->clients[$server] = new SwooleClient($server, $sync, $keep);
        }

        return $this->clients[$server];
    }

    /**
     * 关闭一个连接
     *
     * @param $server
     */
    public function close($server)
    {
        $this->clients[$server]->close();
        unset($this->clients[$server]);
    }
}