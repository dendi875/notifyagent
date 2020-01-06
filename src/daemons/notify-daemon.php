<?php

set_time_limit(0);

if (PHP_SAPI !== 'cli') { // 不是命令行终止运行
    exit('Error: should be invoked via the CLI version of PHP, not the '.PHP_SAPI.' SAPI'.PHP_EOL);
}

require __DIR__.'/../vendor/autoload.php';

/**
 * 常驻内存的 `Beanstalkd` 队列消费者脚本，通过 `daemontools`来保持常驻。
 */

use Walle\Modules\Process\Process;
use Walle\Modules\Queue\BeanstalkdQueue;
use Walle\Modules\Notify\NotifyAgent;
use Walle\Model\Notify;

class NotifyDaemon
{
    const TASK_PROCESS_DB = 'PROCESS_DB';

    /**
     * @var BeanstalkdQueue
     */
    private $queue;

    /**
     * 进程池，队列名为 `key`，子进程 ID 为值
     *
     * 池子中有一个特殊的进程，它专门是用来处理延时的 notify 任务的，这个特殊的进程 key 是 self::TASK_PROCESS_DB
     *
     * @var array
     */
    private $processPoll = [];

    private $config = ['debug' => false];

    protected static $signos = [
        SIGUSR1 => 'SIGUSR1',
        SIGCHLD => 'SIGCHLD',
    ];

    /**
     * NotifyDaemon constructor.
     *
     * @param array $config
     */
    public function __construct($config = [])
    {
        $this->config = array_merge($this->config, $config);
    }


    /**
     * 每个子进程有各自的 BeanstalkdQueue 实例去处理自己的队列。
     *
     * 如果新创建了子进程，需要重新开新的资源连接，否则父子进程使用同一资源连接会互相纠缠导致无法使用。
     */
    private function newQueue()
    {
        if (isset($this->queue) && $this->queue instanceof BeanstalkdQueue) {

            $this->queue->close();

            unset($this->queue);
        }

        $this->queue = BeanstalkdQueue::create(BeanstalkdQueue::MQ_SERVER);
    }

    protected function sigChld()
    {
        $status = null;

        while (($pid = pcntl_waitpid(-1, $status, WNOHANG | WUNTRACED)) > 0) {
            if (($queueName = array_search($pid, $this->processPoll, true)) !== false) {
                unset($this->processPoll[$queueName]);
            }
            $exitMsg = Process::getExitStatus($status);
            $this->debug('pid: '.$pid . " 处理 {$queueName} 队列 ". $exitMsg);
        }
    }

    protected function sigUsr()
    {
        $this->debug('main process receive SIGUSR1 signal');
        $this->cleanup();
        $this->debug('main process graceful exit，exit code：138');
        exit(138);
    }

    /**
     * 父进程发生异常时或收到 SIGUSR1 信号所执行该方法
     *
     * 1、杀死处理 DB 任务的进程
     * 2、等待所有处理队列任务的子进程全部消费完队列正常退出后，回收子进程资源
     * 3、释放队列资源
     */
    protected function cleanup()
    {
        $this->debug(Process::getPids('main process cleanup start'));

        // 杀死处理 DB 任务的进程
        if (isset($this->processPoll[self::TASK_PROCESS_DB])) {
            $this->debug("kill -15 PROCESS_DB");
            posix_kill($this->processPoll[self::TASK_PROCESS_DB], SIGTERM);
            $this->debug('kill -15 PROCESS_DB'.posix_strerror(posix_get_last_error()));
        }

        while (!empty($this->processPoll)) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG | WUNTRACED);
            if ($pid > 0) {
                if (($queueName = array_search($pid, $this->processPoll, true)) !== false) {
                    unset($this->processPoll[$queueName]);
                }
                $exitMsg = Process::getExitStatus($status);
                $this->debug($pid ." 处理 {$queueName} 队列 ". $exitMsg);
            } else {
                continue;
            }
        }

        if ($this->queue) {
            $this->queue->close();
        }

        $this->debug(Process::getPids('main process cleanup end'));
    }

    /**
     * 安装信号处理程序
     */
    protected function registerSignal()
    {
        foreach (self::$signos as $signo => $name) {
            if (!pcntl_signal($signo, [$this, 'signalHandler'])) {
                die("Install signal handler for {$name} failed");
            }
        }
    }

    /**
     * 统一的信号处理程序
     *
     * @param $signo
     */
    protected function signalHandler($signo)
    {
        switch (intval($signo)) {
            case SIGUSR1:   // 需要优雅重新读文件时才发送该信号
                $this->sigUsr();
                break;
            case SIGCLD:
            case SIGCHLD:
                $this->sigChld();
                break;
            default: // 处理其它信号
                break;
        }
    }

    /**
     * 常驻的主进程主要负责任务的分发和调度，主要做以下事：
     * 1、判断用于处理 DB 的进程有没有存活，如果没存话，则 fork
     * 2、取出 notify 队列中的消息，通过消息中的 queueName 属性再 put 到该队列中
     * 3、判断如果进程池没有子进程处理消息中指定的 queueName  ，则 fork 新的子进程去处理
     *
     * 父进程以下情况会终止：
     * 1、任务处理过程中有异常发生时优雅退出（退出码 69），退出后由 daemontools 重启
     * 2、用户执行 kill -SIGUSR1 后优雅的退出（退出码为 138 ）128 + 信号值，退出后由 daemontools 重启
     * 3、用户执行 kill -SIGKILL 暴力的杀死，退出后由 daemontools 重启
     *
     * 注意事项：
     * 一、PHP 作为 CLI 运行时，则必须在每个循环中调用 pcntl_signal_dispatch 函数，以检查是否有新的信号正在等待被调度。
     * 二、父进程在执行任务过程中有异常发生时，首先 kill -SIGTERM 杀死处理 DB 的子进程，然后等待其余所有的子进程（其实其余的都是处理队列的子进程）
     * 都处理完成自己的工作后正常退出，父进程再回收子进程资源，接着父进程再优雅的退出。最后由 daemontools 重新把父进程拉起来，重新建流。
     * 三、用户执行了 kill -SIGUSR1 的处理方式和有异常发生的处理方式一样
     * 四、用户执行了 kill -SIGKILL 后父进程被立即终止掉，子进程变成孤儿进程后也退出，这时由 daemontools 重新把父进程拉起来，重新建流。
     * 所以这也解释了为什么子进程变成孤儿进程后要退出，如果不退出，daemontools 重新把父进程拉起来后，就有两个子进程同时处理 DB 任务，
     * 可能也会有两个子进程同时处理同一个队列任务，这就造成了数据的混乱。
     *
     * 优雅退出：要等待子进程消费完队列后正常终止掉
     * 非优雅：指无论子进程现在处理到哪一步立即终止，比如子进程刚把消息从队列中取出来还没发送，如果这时不优雅的终止而是立即终止则消息可能会被丢失了。
     * 所以在子进程中要判断如果是孤儿进程则要退出。
     *
     * 父进程有异常发生或收到 SIGUSR1  信号时是暂时不执行任务的分发，它会等待下面所有的子进程处理完相应的任务后再退出，处理 DB 任务的子进程因为一般情况下不
     * 会退出，所以由父进程杀了它。
     */
    public function run()
    {
        $this->registerSignal();

        // 上次 fork 子进程去处理 DB 的时间
        $timeLastForkDBProcess = 0;

        $this->newQueue();

        while (true) {
            try {
                // 检查是否有发送给该进程的信号到达，如果有的话则调用注册的信号处理函数
                pcntl_signal_dispatch();

                // 每隔 1 分钟去检查一下处理 DB 任务的那个子进程是否还存活着
                if (time() - $timeLastForkDBProcess > 60 && !isset($this->processPoll[self::TASK_PROCESS_DB])) {
                    $this->fork(['queueName' => self::TASK_PROCESS_DB]);
                    $timeLastForkDBProcess = time();
                }

                while ($notifyRaw = $this->queue->getFromQueue(BeanstalkdQueue::QUEUE_NOTIFY, 5)) {
                    $notify = json_decode($notifyRaw, true);

                    if (empty($notify['queueName'])) {
                        continue;
                    }
                    // 执照通知里的队列名，把通知分放到不同的队列中，让队列各自的子进程去处理
                    $this->queue->putToQueue($notify['queueName'], $notifyRaw);

                    // 如果队列没有子进程去处理，则 fork 新的子进程去处理
                    if (!isset($this->processPoll[$notify['queueName']])) {
                        $this->fork(['queueName' => $notify['queueName']]);
                    }

                    pcntl_signal_dispatch();
                }

                usleep(300000);
            } catch (\Exception $e) {
                $this->debug(Process::getPids('main process occur exception: '.$e->getMessage().' kill self'));
                $this->cleanup();
                exit(69); // 父进程异常优雅退出
            }
        }
    }

    /**
     * @param array $params
     * $params = ['queueName' => self::TASK_PROCESS_DB] 或 $params = ['queueName' => 'xx']
     * @return int
     */
    private function fork(array $params = [])
    {
        $pid = pcntl_fork();

        if ($pid < 0) {
            exit("fork error");
        } else if ($pid > 0) { // 父进程中
            // 父进程中，保存自己 `fork` 出来的子进程ID和该子进程要处理的队列名
            $this->processPoll[$params['queueName']] = $pid;

            return $pid;
        }

        // 子进程中，判断你把我 `fork` 出来是要处理`DB`的事，还是要处理`Queue`的事
        $this->debug(Process::getPids('fork success，for process '.$params['queueName']));
        if ($params['queueName'] === self::TASK_PROCESS_DB) {
            $this->processDB();
        } elseif ($params['queueName']) {
            $this->processQueue($params['queueName']);
        }

        exit(0); // 子进程正常终止
    }

    /**
     * 父进程 fork 出来用于处理 DB 任务的子进程执行的方法。
     *
     * 这个子进程主要做的事情如下：
     * 1、只要不是孤儿进程就每隔 30 秒从数据库中查询可以发送的延时通知
     * 2、删除数据库中的通知
     * 3、把查询出来的通知 put 到 notify 队列中
     *
     * 处理 DB 的子进程以下情况会终止：
     * 1、处理过程中父进程死了（被 kill -9 ），成为孤儿进程后被人为终止（退出码为 1）
     * 2、该子进程处理队列的过程中有异常发生被人为终止（退出码为 69）
     * 3、父进程收到 SIGUSR1 信号后，向该子进程发送 SIGTERM 信号终止
     *
     * 注意事项：
     * 1、使用新的队列资源连接
     * 2、如果成为了孤儿进程需要退出
     * 3、有异常发生要退出，让父进程重新建流
     *
     * 退出码的定义参考：http://tldp.org/LDP/abs/html/exitcodes.html
     */
    private function processDB()
    {
        $timeLast = 0;  // 上次获取延时 notify 的时间

        $this->newQueue();  // 使用新的队列连接资源
        try {
            while (true) {
                // 每隔 30 从数据库中查询可以发送的延时通知
                if (time() - $timeLast > 30) {
                    $notifys = Notify::getInstance()->findDelayNotify(['timeDelayedSend' => date('Y-m-d H:i:s')]);

                    foreach ($notifys as $notify) {
                        $this->queue->putToQueue(BeanstalkdQueue::QUEUE_NOTIFY, json_encode($notify));
                        Notify::getInstance()->deleteById($notify->id);
                    }

                    $timeLast = time();
                } else {
                    usleep(500000); // 0.5 s
                }

                if (posix_getppid() === 1) {
                    // 记录下日志，把它 fork 出来的父进程已经死了，变成了孤儿进程
                    $this->debug(Process::getPids('processDB become an orphan exit'));
                    exit(1);
                }
            }
        } catch (\Exception $e) {
            $this->debug(Process::getPids('processDB exception：'.$e->getMessage().' exit'));
            exit(69);
        }
    }

    /**
     * 父进程 fork 出来用于处理队列任务的子进程执行的方法。
     *
     * 这个子进程主要做的事情如下：
     * 通过队列名从指定队列中获取通知，然后进行通知的发送
     *
     * 处理队列的子进程以下情况会终止：
     * 1、执行任务过程中父进程死了，成为孤儿进程后被人为终止（退出码为 1）
     * 2、执行任务过程中有异常发生被人为终止（退出码为 69）
     * 3、该子进程对应的队列里没有消息时正常终止（退出码为0）
     *
     * 注意事项：参考 processDB 方法
     *
     * 退出码的定义参考：http://tldp.org/LDP/abs/html/exitcodes.html
     *
     * @param $queueName
     */
    private function processQueue($queueName)
    {
        $this->newQueue();

        try {
            while ($notifyRaw = $this->queue->getFromQueue($queueName, 5)) {
                $notify = json_decode($notifyRaw, true);
                NotifyAgent::getInstance()->realSend($notify);

                if (posix_getpid() === 1) {
                    $this->debug(Process::getPids('processQueue '.$queueName.'become an orphan exit'));
                    exit(1);
                }
            }
        } catch (\Exception $e) {
            $this->debug(Process::getPids('processQueue '.$queueName.'exception：'.$e->getMessage().' exit'));
            exit(69);
        }
    }

    private function debug($msg)
    {
        $fileStore = sys_get_temp_dir() . '/notify-log-'.date('Ymd').'.log';

        $content = date('Y-m-d H:i:s').PHP_EOL.$msg.PHP_EOL;

        if ($this->config['debug']) {
            file_put_contents($fileStore, $content, FILE_APPEND | LOCK_EX);
        }
    }
}

$daemonNotify = new NotifyDaemon(['debug' => true]);
$daemonNotify->run();