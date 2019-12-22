<?php

set_time_limit(0);

if (PHP_SAPI !== 'cli') { // 不是命令行终止运行
    exit('Error: should be invoked via the CLI version of PHP, not the '.PHP_SAPI.' SAPI'.PHP_EOL);
}

require __DIR__.'/../vendor/autoload.php';

/**
 * 常驻内存的 `Beanstalkd` 队列消费者脚本，通过 `daemontools`来保持常驻。
 *
 * 该脚本主要逻辑为：
 *      进程池中
 *
 *
 * 子进程以下情况会终止：
 * 1、子进程处理完自己的队列自然终止
 * 2、父进程收到 SIGTERM 信号后，向子进程发送 SIGTERM 信号终止
 * 3、子进程在处理自己队列时异常终止
 *
 * 父进程以下情况会终止：
 *
 */

use Exception;
use Walle\Modules\Queue\BeanstalkdQueue;
use Walle\Modules\Helper\Utils;

class DaemonNotify
{
    /**
     * 进程池，队列名为 `key`，子进程 ID 为值
     *
     * 池子中有一个特殊的进程，它专门是用来处理延时的 notify 的，这个特殊的进程 key 是 processDB
     *
     * @var array
     */

    private $queue;

    private $processPoll = [];

    protected $config = ['debug' => false];

    protected static $signos = [SIGTERM, SIGCHLD];

    public function __construct($config = [])
    {
        $this->config = array_merge($this->config, $config);
    }

    private function newQueue()
    {
        // 如果新创建了子进程，需要重新开新的资源连接，否则子进程之间互相纠缠导致无法使用
        if (isset($this->queue) && $this->queue instanceof BeanstalkdQueue) {
            $this->queue->close();
            unset($this->queue);
        }

        $this->queue = BeanstalkdQueue::create(BeanstalkdQueue::MQ_SERVER);
    }

    /**
     * 父进程接收到 SIGTERM 信号的处理
     *
     * 给所有子进程发送 SIGTERM 信号，然后再重新 fork 子进程来处理
     */
    protected function sigTerm()
    {
        $this->debug('receive terminate signal');

        posix_kill(0, SIGTERM);

        // 重启所有子进程
        $this->fork(['queueName' => 'processDB']);

        $queues = $this->mq->listQueue('Q_');

        if (is_array($queues) && count($queues) > 0) {
            foreach ($queues as $queueName) {
                $queueLength = $this->mq->getQueueLength($queueName);
                if ($queueLength > 0 && !isset($this->processPoll[$queueName])) {
                    $this->fork(['queueName' => $queueName]);
                    $this->debug($queueName.'(size:'.$queueLength.') process missing, try to restart');
                }
            }
        }
    }

    /**
     * 父进程接收到 SIGCHLD 信号的处理
     */
    protected function sigChld()
    {
        $status = null;

        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            if (($queueName = array_search($pid, $this->processPoll, true)) !== false) {
                unset($this->processPoll[$queueName]);
            }
            $msg = $this->getStatus($status);
            $this->debug($pid . ' '. $msg);
        }
    }

    protected function registerSignal()
    {
        foreach (self::$signos as $signo) {
            pcntl_signal($signo, [&$this, 'signalHandler']);
        }
    }

    protected function signalHandler($signo)
    {
        switch ($signo) {
            case SIGTERM: // 处理`kill`命令默认发送的SIGTERM（15）信号
                $this->sigTerm();
                exit(0);
            case SIGCHLD:
                $this->sigChld();
            default:
                // 处理其它信号
                break;
        }
    }

    /**
     * 父进程执行的方法，主要的职责是：
     *
     * 1、调度作用。
     *
     * 判断要不要 fork 子进程去处理队列
     * 判断用于处理`DB`的进程有没有存活，如果没存话，则`fork`
     *
     * 如果父进程有异常发生则捕捉异，给所有的子进程（包括处理DB的进程）发送 SIGTERM 信号，
     * 终止所有子进程然后再重新 fork 子进程来去处理 DB 和 Queue
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

                if (time() - $timeLastForkDBProcess > 60 && !isset($this->processPoll['processDB'])) {
                    $this->fork(['queueName' => 'processDB']);
                    $timeLastForkDBProcess = time();
                }

                while ($rawNotify = $this->queue->getFromQueue(BeanstalkdQueue::QUEUE_NOTIFY, 5)) {
                    $notify = unserialize($rawNotify);
                    if (empty($notify->queueName)) {
                        continue;
                    }
                    // 执照通知里的队列名，把通知分放到不同的队列中，让队列各自的子进程去处理
                    $this->queue->putToQueue($notify->queueName, $rawNotify);

                    // 如果队列没有子进程去处理，则 fork 新的子进程去处理
                    if (!isset($this->processPoll[$this->queueName])) {
                        $this->fork(['queueName' => $this->queueName]);
                    }

                    pcntl_signal_dispatch();
                }
            } catch (Exception $e) {

            }
        }
    }

    protected function fork(array $params = [])
    {
        $pid = pcntl_fork();
        if ($pid < 0) {
            exit("fork error");
        } else if ($pid > 0) { // 父进程中
            // 父进程中，保存自己 `fork` 出来的子进程ID
            $this->processPoll[$params['queueName']] = $pid;
            $this->debug('child：'.$pid.' fork success，for process '.$params['queueName']);
            return $pid;
        }

        // 子进程中，判断你把我 `fork` 出来是要处理`DB`的事，还是要处理`Queue`的事
        if ($params['queueName'] === 'processDB') {
            $this->processDB();
        } elseif ($params['queueName']) {
            $this->processQueue($params['queueName']);
        }

        exit();
    }

    /**
     * 父进程 fork 出来的子进程执行的方法。
     *
     * 这个子进程主要做的事情如下：
     * 1、只要 fork 它的父进程还活着，就每隔 30 秒从数据库中查询可以发送的延时通知
     * 2、把查询出来的通知 put 到 notify 队列中
     *
     * 注意事项：
     * 1、不能直接用 while (1) {} ，因为一旦 fork 它的父进程已经死了而且数据库中延时通知又特别多的情况下，
     * 就会一直向 notify 队列中 put 通知，如果父进程一直没有被重新拉起来，或者过了很长时间才被拉起来那么就会
     * 在短时间内造成队列中的消息堆积，从而导致内存使用率暴涨，严重的话可能会拖垮机器，所以要判断父进程存活的情况
     * 下干活。虽然我们用 `daemontools`来保证父进程死了会被自动拉起，但代码上还是要考虑这种情况。
     * 2、
     */
    private function processDB()
    {
        $timeLast = 0;  // 上次获取延时 notify 的时间

        while (posix_getppid() > 1) {
            // 检查是否有发送给该进程的信号到达，如果有的话则调用注册的信号处理函数
            pcntl_signal_dispatch();

            if (time() - $timeLast > 30) {
                // TODO 查询数据库中的延时通知再 put 到 notify 队列里

                $timeLast = time();
            } else {
                usleep(500000); // 0.5 s
            }
        }

        // 记录日志下日志，把它 fork 出来的父进程已经死了，变成了孤儿进程了，
    }

    /**
     * 父进程 fork 出来的子进程执行的方法。
     *
     * @param $queueName
     */
    private function processQueue($queueName)
    {

    }

    /**
     * 返回进程退出时的状态信息
     *
     * @param $status
     * @return string
     */
    private function getExitStatus($status)
    {
        if (pcntl_wifexited($status)) {
            $res = sprintf("normal termination, exit status = %d", pcntl_wexitstatus($status));
        } else if (pcntl_wifsignaled($status)) {
            $res = sprintf("abnormal termination, signal number = %d", pcntl_wtermsig($status));
        } else if (pcntl_wifstopped($status)) {
            $res = sprintf("child stoped, signal number = %d", pcntl_wstopsig($status));
        }

        return $res;
    }

    private function getPids($name)
    {
        $pid = posix_getpid();

        $pids = sprintf("%s：pid = %d, ppid = %d, pgid = %d, sid = %d",
            $name, $pid, posix_getppid(), posix_getpgid($pid), posix_getsid($pid));

        return $pids;
    }

    private function debug($msg)
    {
        $fileStore = sys_get_temp_dir() . '/notify-log-'.date('Ymd').'.log';

        $content = date('Y-m-d H:i:s').PHP_EOL.$msg;

        if ($this->config['debug']) {
            file_put_contents($fileStore, $content, FILE_APPEND | LOCK_EX);
        }
    }
}

$daemonNotify = new DaemonNotify(['debug' => true]);
$daemonNotify->run();