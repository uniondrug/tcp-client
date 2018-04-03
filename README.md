# TCPClient component for uniondrug/framework

Uniondrug微服务基于TCP的客户端的封装，加入了Trace功能。

## 安装

```shell
$ cd project-home
$ composer require uniondrug/tcp-client
```

修改 `app.php` 配置文件，导入服务。服务名称：`tcpClient`。

```php
return [
    'default' => [
        ......
        'providers'           => [
            ......
            \Uniondrug\TcpClient\TcpClientServiceProvider::class,
        ],
    ],
];
```

## 使用

```php
    // 在 Injectable 继承下来的对象中：
    $data = $this->getDI()->getShared('tcpClient')->get($url);

    // 或者 直接使用 属性方式
    $data = $this->tcpClient()->get($url)
```
