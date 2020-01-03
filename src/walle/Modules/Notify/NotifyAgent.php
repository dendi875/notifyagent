<?php
/**
 * Walle\Modules\Notify\NotifyAgent
 *
 * @author     <dendi875@163.com>
 * @createDate 2019-12-23 20:12:29
 * @copyright  Copyright (c) 2019 https://github.com/dendi875
 */

namespace Walle\Modules\Notify;

use LogicException;
use Walle\Model\Notify;
use Walle\Modules\Helper\Http;
use Walle\Modules\Queue\BeanstalkdQueue;
use Walle\Modules\Log\Log;

class NotifyAgent
{
    const MAX_RETRY_TIMES = 9;

    private static $instance;

    public static function getInstance()
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }

        return static::$instance;
    }


    private function __construct()
    {

    }

    /**
     * 增加一个 notify 通知
     *
     * @param NotifyMessage $message
     * @return mixed
     * @throws LogicException
     */
    public function addNotify(NotifyMessage $message)
    {
        // 如果不需要应答，则只通知一次
        if (NotifyMessage::NEED_RESPONSE_NO === $message->getNeedResponse()) {
            $message->setRunOnce(NotifyMessage::RUN_ONCE_YES);
        }

        // 如果指定了发送时间，并且发送时间比当前时间要大，则不是立即发送
        if (!empty($message->getSendTime()) && strtotime($message->getSendTime()) > time()) {
            $message->setSendImmediate(false);
        }

        // 检查必须指定一种发送时间（是立即发送还是延时发送，或被动触发）
        if (!$message->getSendImmediate() && empty($message->getSendTime()) && empty($message->getFkey())) {
            throw new LogicException('must specify a sending time');
        }

        $notify = $message->getNotifyData();

        if ($message->getSendImmediate()) {
            // 如果是需要立即发送的通知，直接放入队列
            $this->pushNotifyToMQ($notify);
        } else {
            // 延时发送的通知，写入数据库
            return $this->pushNotifyToDB($notify);
        }
    }


    private function pushNotifyToMQ(array $notify)
    {
        BeanstalkdQueue::create(BeanstalkdQueue::MQ_SERVER)
                        ->putToQueue(BeanstalkdQueue::QUEUE_NOTIFY, json_encode($notify));
    }

    private function pushNotifyToDB(array $notify)
    {
        return Notify::getInstance()->add($notify);
    }


    /**
     * 真实的发送通知
     *
     * @param array $notify
     * @return bool
     */
    public function realSend(array $notify)
    {
        set_time_limit(0);

        $startTime = time();
        $actualResponse = $this->request($notify);
        $duration = time() - $startTime;

        $status = $this->parseStatus($notify, $actualResponse);

        $notify['actualResponse'] = $actualResponse;
        $notify['status'] = $status;
        $notify['duration'] = $duration;

        $this->injectNotifyLog($notify);

        // 在只通知一次或成功的情况下返回
        if ($notify['runOnce'] === NotifyMessage::RUN_ONCE_YES || $notify['status'] == NotifyMessage::STATUS_SUCCESS) {
            return true;
        }

        // 失败并且需要重试的情况下，进行重试
        return $this->retry($notify);
    }

    /**
     * 发送 Http 请求
     *
     * @param array $notify
     * @return string
     */
    private function request(array $notify)
    {
        $timeout = 3600000; // 默认连接超时1小时，保证能等待一些耗时较长的脚本执行结束
        if ($notify['needResponse'] === NotifyMessage::NEED_RESPONSE_NO) {
            $timeout = 1000;
        }

        $actualResponse = Http::curlRequest($notify['url'], $notify['data'], $notify['method'], $timeout, $notify['contentType']);

        return $actualResponse;
    }

    /**
     * 解析通知的状态
     *
     * @param array $notify
     * @param $actualResponse
     * @return int
     */
    private function parseStatus(array $notify, $actualResponse)
    {
        if ($notify['needResponse'] === NotifyMessage::NEED_RESPONSE_NO) {  // 不需要应答的条件下，默认是成功的
            $status = NotifyMessage::STATUS_SUCCESS;
        } else {
            if (stripos(trim($actualResponse), $notify['expectResponse']) !== false) {
                $status = NotifyMessage::STATUS_SUCCESS;
            } else {
                $status = NotifyMessage::STATUS_FAILURE;
            }
        }

        return $status;
    }


    /**
     * 把 NotifyAgent 通知执行情况记录到日志系统中
     *
     * @param array $notify
     */
    private function injectNotifyLog(array $notify)
    {
        if ($notify['status'] === NotifyMessage::STATUS_SUCCESS) {
            $method = 'info';
        } else {
            $method = 'error';
        }

        $message = '';
        $context = [];

        array_walk($notify, function ($item, $key) use (&$message, &$context) {
            $message .= $key.':'.'{'.$key.'}'.' ';
            $context[$key] = $item;
        });

        Log::$method('notify-agent', trim($message), $context);
    }

    /**
     * @param array $notify
     * @return bool
     */
    private function retry(array $notify)
    {
        $retryTimes = intval($notify['retryTimes']);

        if ($retryTimes >= static::MAX_RETRY_TIMES) {
            return false;
        }

        $notify['retryTimes']  = $retryTimes + 1;

        $delay = static::MAX_RETRY_TIMES * pow(2, $notify['retryTimes']);

        BeanstalkdQueue::create(BeanstalkdQueue::MQ_SERVER)
                        ->putToQueue(BeanstalkdQueue::QUEUE_NOTIFY, json_encode($notify), 2, $delay);

        // TODO 重试第三次发送短信通知
    }

    public function sendNotify()
    {

    }

    public function cancelNotify()
    {

    }

    /**
     * 防止实例被克隆（这将创建它的第二个实例）
     */
    private function __clone()
    {

    }

    /**
     * 防止被反序列化（这将创建它的第二个实例）
     */
    private function __wakeup()
    {

    }
}