[TOC]
# NotifyAgent

一个用于微服务之间异步通信的系统
-------------------------------------
## 背景

在进行微服务架构时，一个相当关键的设计点是：我们的服务应如何相互通信？

微服务之间相互通信可分为同步和异步。让我们一 一讲解。


### 同步通信

调用方请求服务，并等待服务完成。只有当它接收到服务的结果时，它才继续工作。可以定义超时，如果服务没有在规定的时间内结束，则假定调用失败，调用方继续工作。

* 优点
    - 易于编程 
    - 更好的实时响应，结果马上就知道

* 缺点
    - 调用方应用程序将被阻塞，或者接收响应，或者发生超时它才能继续工作
    - 服务必须准备就绪，如果被调方服务不可用，则可能会丢失请求和信息
    
* 使用场景

    - 如果是一个读请求，特别是当它需要实时数据时
    - 请求严重依赖于调用反馈的结果时

### 异步通信

调用方发起一个服务调用，但不等待结果。调用方立即继续执行其余工作而不管结果如何。如果客户端需要处理服务端反馈的结果，有两种模式来实现这一点：要么客户端反复请求服务器上的处理结果（**轮询**），要么服务端在完成处理后调用客户端的服务来报告反馈（**回调**）。

* 优点
    - 没有阻塞
    - 因为是异步调用，在大流量场景下，应用不容易挂掉
    - 当发出请求时，服务无需可用

* 缺点
    - 没有即时应答。如果需要处理服务端反馈的结果，要额外提供轮询或回调机制，这增加了编程的复杂度
    
* 使用场景
    - 如果是大量写请求且不能丢失请求数据时，因为如果下游系统宕机并且你继续向其发送同步调用，那么将丢失请求
    - 对于长时间执行的 `API` 调用，`SQL`报表统计，或其它耗时较长的操作
    - 你需要处理某个业务逻辑，但是不需要立刻处理它，而且不需要知道它的响应结果

## NotifyAgent 概述

基于`beanstalkd`消息队列和`PHP`多进程及信号实现的一个用于应用与应用之间异步和延迟调用的系统，称之为通知代理系统。

我们把异步或延迟调用时的信息，比如：请求的 `url`，请求的 `method`，请求的参数，延时的时间等统一封装后称为一个通知（`Notify`）。

![NotifyAgent](https://github.com/dendi875/images/blob/master/PHP/notifyagent/NotifyAgent.png)

可能需要满足的需求：

* 调用方式要求：如：`GET`或`POST`，`x-www-form-urlencoded`或`json`
* 时间要求：立即发送或指定时间发送
* 应答要求：要求正确应答或不需要应答
* 重发要求：需要应答的情况下，如果未收到正确的应答，重发直至得到正确应答或超过最大重发次数
* 顺序/并发执行要求：发往某一个地址的通知，可以按需排队依次执行或并发执行
* 被动触发要求：创建通知时并不明确要发送的时间，需事后触发

![NotifyAgent-Process](https://github.com/dendi875/images/blob/master/PHP/notifyagent/NotifyAgent-Process.png)

### 架构模型

![NotifyAgent_Architecture_Diagram](https://github.com/dendi875/images/blob/master/PHP/notifyagent/NotifyAgent_Architecture_Diagram.png)


常驻的主进程主要负责任务的分发和调度，主要做以下事：
- 每隔一段时间检查用于处理 `DB` 的进程有没有存活，如果没存话，则 `fork`
- 取出 `notify` 队列中的消息，通过消息中的 `queueName` 属性再 `put` 到该队列中
- 判断如果进程池没有子进程处理消息中指定的 `queueName`，则 `fork` 新的子进程去处理

用于处理 `DB` 任务的子进程主要做的事情如下：

- 只要不是孤儿进程就每隔一段时间从数据库中查询可以发送的延时通知
- 删除数据库中的通知
- 把查询出来的通知 `put` 到 `notify` 队列中


用于处理队列任务的子进程主要做的事情如下：	

* 通过队列名从指定队列中获取通知，然后进行通知的发送

## 基本使用

### 安装

```shell
$ composer install
```

### 使用示例

* 构建 `notify daemontools Service`

```sh
# cd /scratch/service/
# mkdir notify
# cd notify/
```

创建一个run文件，其中包含：

```sh
#!/bin/sh
exec 2>&1
exec su - root -c "php /mnt/hgfs/github/notifyagent/src/daemons/notify-daemon.php" 1>> /data1/log/php-scripts/notify-daemon.php
```

赋予执行权限

```sh
# chmod u+x run
```

安装 notify 服务并实际开始运行它

```sh
# ln -s /scratch/service/notify/ /service/notify
```

确认进程正在运行

```sh
# ps -ef | grep notify-daemon.php
```

到这里我们的守护进程已经在后台运行了，而且被`daemontools`监护着，我们通过`beanstalk_console`可以看到名为`notify`的`tube`已经产生

![beanstalk_console_notify](https://github.com/dendi875/images/blob/master/PHP/notifyagent/beanstalk_console_notify.png)


* 配置测试站点来模拟被调用方（可以按自己的情况来配置）

```shell
server {
        listen            80; 
        listen            443 ssl;
        server_name       a.demo.test;
        ssl_certificate   /usr/local/nginx/conf/ssl/nginx.crt; 
        ssl_certificate_key /usr/local/nginx/conf/ssl/nginx.key; 
        root   /data1/www/demo/a;
        index  index.php index.html index.htm;
        include fpm_config;
}

server {
        listen            80; 
        listen            443 ssl;
        server_name       b.demo.test;
        ssl_certificate   /usr/local/nginx/conf/ssl/nginx.crt;
        ssl_certificate_key /usr/local/nginx/conf/ssl/nginx.key;
        root   /data1/www/demo/b;
        index  index.php index.html index.htm;
        include fpm_config;
}
```

假设测试文件 */data1/www/demo/a/index.php* 内容为：

```php
<?php

$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (stripos($contentType, 'application/json') !== false && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
} else {
    $data = $_REQUEST;
}

$id = $data['id'];
$name = $data['name'];

echo "a, success, id = $id, name = $name";
```

假设测试文件 */data1/www/demo/b/index.php* 内容为：

```php
<?php

$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (stripos($contentType, 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true);
} else {
    $data = $_REQUEST;
}

$id = $data['id'];
$name = $data['name'];

echo "b, success, id = $id, name = $name";
```

* 使用`Walle\Modules\Notify\NotifyAgent`类来测试发送各种通知

```sh
[dendi875@localhost src]$ php test.php 
```

1. 测试异步立即发送通知
2. 测试异步延迟发送通知
3. 测试通过 fkey 创建通知，然后等待外部触发
4. 测试不同地址，通知是并发执行的
5. 测试相同地址，通知也是可以并发执行的

查看 **debug** 日志

```sh
[dendi875@localhost ~]$ tail -f /tmp/notify-log-20200114.log 
2020-01-14 16:14:30
fork success，for process PROCESS_DB：pid = 5971, ppid = 5958, pgid = 3472, sid = 2894

2020-01-14 16:14:43
fork success，for process Q_88D1BD37A1EA3869FD52DE767A51A005：pid = 5975, ppid = 5958, pgid = 3472, sid = 2894
2020-01-14 16:14:48
pid: 5975 处理 Q_88D1BD37A1EA3869FD52DE767A51A005 队列 normal termination, exit status = 0

2020-01-14 16:17:06
fork success，for process Q_88D1BD37A1EA3869FD52DE767A51A005：pid = 5991, ppid = 5958, pgid = 3472, sid = 2894
2020-01-14 16:17:11
pid: 5991 处理 Q_88D1BD37A1EA3869FD52DE767A51A005 队列 normal termination, exit status = 0
2020-01-14 16:19:10
fork success，for process Q_88D1BD37A1EA3869FD52DE767A51A005：pid = 6033, ppid = 5958, pgid = 3472, sid = 2894
2020-01-14 16:19:15
pid: 6033 处理 Q_88D1BD37A1EA3869FD52DE767A51A005 队列 normal termination, exit status = 0
2020-01-14 16:21:14
fork success，for process Q_88D1BD37A1EA3869FD52DE767A51A005：pid = 6048, ppid = 5958, pgid = 3472, sid = 2894
2020-01-14 16:21:19
pid: 6048 处理 Q_88D1BD37A1EA3869FD52DE767A51A005 队列 normal termination, exit status = 0

2020-01-14 16:24:00
fork success，for process Q_88D1BD37A1EA3869FD52DE767A51A005：pid = 6065, ppid = 5958, pgid = 3472, sid = 2894
2020-01-14 16:24:05
pid: 6065 处理 Q_88D1BD37A1EA3869FD52DE767A51A005 队列 normal termination, exit status = 0

2020-01-14 16:24:42
fork success，for process Q_DCF2CB4B39DB7509082B81DDA79E7297：pid = 6072, ppid = 5958, pgid = 3472, sid = 2894
2020-01-14 16:24:42
fork success，for process Q_88D1BD37A1EA3869FD52DE767A51A005：pid = 6071, ppid = 5958, pgid = 3472, sid = 2894
2020-01-14 16:24:47
pid: 6071 处理 Q_88D1BD37A1EA3869FD52DE767A51A005 队列 normal termination, exit status = 0
2020-01-14 16:24:52
pid: 6072 处理 Q_DCF2CB4B39DB7509082B81DDA79E7297 队列 normal termination, exit status = 0

2020-01-14 16:25:26
fork success，for process Q_741ADBE87A04B9D6C970C5A6B8EFE350：pid = 6079, ppid = 5958, pgid = 3472, sid = 2894
2020-01-14 16:25:26
fork success，for process Q_3E456A2B6C723064137049537840E4B4：pid = 6078, ppid = 5958, pgid = 3472, sid = 2894
2020-01-14 16:25:32
pid: 6078 处理 Q_3E456A2B6C723064137049537840E4B4 队列 normal termination, exit status = 0
2020-01-14 16:25:37
pid: 6079 处理 Q_741ADBE87A04B9D6C970C5A6B8EFE350 队列 normal termination, exit status = 0
```

每次调用发送的信息我们都使用 [Log](https://github.com/dendi875/log) 系统来记录，所以可以通过`Kinaba`来查看我们发送的历史

异步立即发送通知

![beanstalk_console_immediate](https://github.com/dendi875/images/blob/master/PHP/notifyagent/beanstalk_console_immediate.png)

异步延迟发送通知

![beanstalk_console_delay](https://github.com/dendi875/images/blob/master/PHP/notifyagent/beanstalk_console_delay.png)

通过 fkey 创建通知，然后等待外部触发

![beanstalk_console_fkey](https://github.com/dendi875/images/blob/master/PHP/notifyagent/beanstalk_console_fkey.png)


不同地址，通知是并发执行的

![beanstalk_console_concurrency1](https://github.com/dendi875/images/blob/master/PHP/notifyagent/beanstalk_console_concurrency1.png)


相同地址，通知也是可以并发执行的

![beanstalk_console_concurrency2](https://github.com/dendi875/images/blob/master/PHP/notifyagent/beanstalk_console_concurrency2.png)

### **Graceful restart**（优雅重启）

如果修改了代码，要进行**Graceful restart**（优雅重启），只需发送 `SIGUSR1`信号给常驻进程，常驻进程优雅退出后，再由`deamontool`来重新拉起来

首先找出主进程`PID`

```
[root@localhost src]# ps -elf | grep notify-daemon
0 S root      5957  4924  0  80   0 -  1284 -      16:14 pts/0    00:00:00 su - root -c php /mnt/hgfs/github/notifyagent/src/daemons/notify-daemon.php
4 S root      5958  5957  0  80   0 - 11549 -      16:14 pts/0    00:00:02 php /mnt/hgfs/github/notifyagent/src/daemons/notify-daemon.php
1 S root      5971  5958  0  80   0 - 11491 -      16:14 pts/0    00:00:00 php /mnt/hgfs/github/notifyagent/src/daemons/notify-daemon.php
0 S root      7379  3431  0  80   0 -  1494 -      18:40 pts/0    00:00:00 grep --color=auto notify-daemon
```

发送`SIGUSR1`信号

```sh
[root@localhost src]# kill -SIGUSR1 5958
```

再次看`notify-daemon`进程

```sh
[root@localhost src]# ps -elf | grep notify-daemon
0 S root      7386  4924  0  80   0 -  1284 -      18:41 pts/0    00:00:00 su - root -c php /mnt/hgfs/github/notifyagent/src/daemons/notify-daemon.php
4 S root      7387  7386  1  80   0 - 11455 -      18:41 pts/0    00:00:00 php /mnt/hgfs/github/notifyagent/src/daemons/notify-daemon.php
1 S root      7400  7387  0  80   0 - 11491 -      18:41 pts/0    00:00:00 php /mnt/hgfs/github/notifyagent/src/daemons/notify-daemon.php
0 S root      7406  3431  0  80   0 -  1494 -      18:42 pts/0    00:00:00 grep --color=auto notify-daemon
```

查看**debug**日志

```sh
2020-01-14 18:41:24
main process receive SIGUSR1 signal
2020-01-14 18:41:24
main process cleanup start：pid = 5958, ppid = 5957, pgid = 3472, sid = 2894
2020-01-14 18:41:24
kill -15 PROCESS_DB
2020-01-14 18:41:24
kill -15 PROCESS_DBSuccess
2020-01-14 18:41:24
5971 处理 PROCESS_DB 队列 abnormal termination, signal number = 15
2020-01-14 18:41:24
main process cleanup end：pid = 5958, ppid = 5957, pgid = 3472, sid = 2894
2020-01-14 18:41:24
main process graceful exit，exit code：138
2020-01-14 18:41:24
fork success，for process PROCESS_DB：pid = 7400, ppid = 7387, pgid = 3472, sid = 2894
```

## 思考和总结

要做到正常重启 **Graceful restart**， 通过`pcntl_signal`函数， 在接受到 **restart/shutdown**信号时做关闭清理动作， 保证不会因为重启/关闭而使得正在执行的逻辑出错

### 注意事项

- 作为后台服，需要常驻后台运行， 那么丁点的内存泄露都是不能接受的
- 作为后台服，畸形数据导致进程异常退出， 也是不可接受的
- 作为后台服务，要能做到**Graceful restart**
- 作为后台服务，对资源的使用必须在可接受的范围以内
- 子进程异常退出时，父进程有机会重建流程

## 参考资料

- [PHP 多进程编程](https://github.com/dendi875/PHP/blob/master/PHP%E5%A4%9A%E8%BF%9B%E7%A8%8B%E7%BC%96%E7%A8%8B.md)
- [进程的守护神 - daemontools](https://github.com/dendi875/Linux/blob/master/daemontools%E7%A0%94%E7%A9%B6%E5%AD%A6%E4%B9%A0.md)

