<?php
/**
 * Walle\Modules\Notify\NotifyMessage
 *
 * @author     <quan.zhang@guanaitong.com>
 * @createDate 2019-12-30 21:12:45
 * @copyright  Copyright (c) 2019 guanaitong.com
 */

namespace Walle\Modules\Notify;

use InvalidArgumentException;
use UnexpectedValueException;

class NotifyMessage
{
    // needResponse constant
    const NEED_RESPONSE_YES = 1;
    const NEED_RESPONSE_NO = 2;

    // runOnce constant
    const RUN_ONCE_YES = 1;
    const RUN_ONCE_NO = 2;

    // notify status
    const STATUS_SUCCESS = 1;
    const STATUS_FAILURE = 2;

    const CONTENT_TYPE_FORM = 'application/x-www-form-urlencoded';
    const CONTENT_TYPE_JSON = 'application/json';

    // 方式要求
    private $caller = 'unknown'; // 调用者
    private $url;  // http url
    private $method; // http method
    private $contentType; // http content-type
    private $data = []; // 请求数据

    // 时间要求
    private $sendImmediate; // 是否立即发送
    private $sendTime; // 延时发送的时间

    // 应答要求
    private $needResponse; // 是否需要应答
    private $expectResponse; // 预期的应答
    private $runOnce; // 需要应答的情况下，预期应答和实际应答不符时是否只执行一次

    // 顺序/并发执行要求
    private $queueName; // 队列名
    private $group;

    // 被动触发要求
    private $fkey = '';

    /**
     * NotifyMessage constructor.
     * @param $url
     */
    private function __construct($url)
    {
        $this->url = $url;

        // 默认的调用方式
        $this->method = 'POST';
        $this->contentType = static::CONTENT_TYPE_JSON;

        // 默认是立即发送
        $this->sendImmediate = true;

        // 默认的应答要求
        $this->needResponse = static::NEED_RESPONSE_YES;
        $this->expectResponse = 'success';
        $this->runOnce = static::RUN_ONCE_YES;
    }

    /**
     * @param $url
     * @return object
     */
    public static function create($url)
    {
        $class = get_called_class();
        $message = new $class($url);

        return $message;
    }

    public function getNotifyData()
    {
        if (empty($this->queueName)) {
            if (empty($this->group)) {
                $this->queueName = 'Q_'.strtoupper(md5($this->url));
            } else {
                $this->queueName = 'Q_'.strtoupper(md5($this->url.'_'.$this->group));
            }
        }

        if (static::CONTENT_TYPE_JSON === $this->contentType) {
            $data = json_encode($this->data);
        } else {
            $data = http_build_query($this->data);
        }

        $notify = [
            'caller' => $this->caller,
            'url'    => $this->url,
            'method' => $this->method,
            'contentType' => $this->contentType,
            'data'  => $data,
            'timeDelayedSend' => $this->sendTime,
            'needResponse' => $this->needResponse,
            'expectResponse' => $this->expectResponse,
            'runOnce'       => $this->runOnce,
            'queueName'     => $this->queueName,
            'fKey'          => $this->fkey
        ];

        return $notify;
    }

    public function setCaller($caller)
    {
        if (is_string($caller) && !empty($caller)) {
            $this->caller = $caller;
        } elseif (is_int($caller)) {
            $this->caller = (string) $caller;
        } else {
            throw new InvalidArgumentException('caller only supports strings or integers');
        }

        return $this;
    }

    public function getCaller()
    {
        return $this->caller;
    }

    public function setUrl($url)
    {
        $this->url = $url;

        return $this;
    }

    public function getUrl()
    {
        return $this->url;
    }

    public function setMethod($method)
    {
        $this->method = $method;

        return $this;
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function setContentType($contentType)
    {
        if (in_array($contentType, [static::CONTENT_TYPE_FORM, static::CONTENT_TYPE_JSON])) {
            $this->contentType = $contentType;
        } else {
            throw new UnexpectedValueException('http content-type wrong');
        }

        return $this;
    }

    public function getContentType()
    {
        return $this->contentType;
    }

    public function setData(array $data)
    {
        $this->data = $data;

        return $this;
    }

    public function getData()
    {
        return $this->data;
    }

    public function setSendImmediate($sendImmediate)
    {
        if (is_bool($sendImmediate)) {
            $this->sendImmediate = $sendImmediate;
        } else {
            throw new InvalidArgumentException('sendImmediate must be boolean');
        }

        return $this;
    }

    public function getSendImmediate()
    {
        return $this->sendImmediate;
    }

    public function setSendTime($sendTime)
    {
        $this->sendTime = $sendTime;

        return $this;
    }

    public function getSendTime()
    {
        return $this->sendTime;
    }

    public function setNeedResponse($needResponse)
    {
        if (in_array($needResponse, [static::NEED_RESPONSE_YES, static::NEED_RESPONSE_NO])) {
            $this->needResponse = $needResponse;
        } else {
            throw new UnexpectedValueException('needResponse can only be 1 or 2');
        }

        return $this;
    }

    public function getNeedResponse()
    {
        return $this->needResponse;
    }

    public function setExpectResponse($expectResponse)
    {
        if (is_string($expectResponse)) {
            $this->expectResponse = $expectResponse;
        } else {
            throw new InvalidArgumentException('expectResponse must be string');
        }

        return $this;
    }

    public function getExpectResponse()
    {
        return $this->expectResponse;
    }

    public function setRunOnce($runOnce)
    {
        if (in_array($runOnce, [static::RUN_ONCE_YES, static::RUN_ONCE_NO])) {
            $this->runOnce = $runOnce;
        } else {
            throw new UnexpectedValueException('runOnce can only be 1 or 2');
        }

        return $this;
    }

    public function getRunOnce()
    {
        return $this->runOnce;
    }

    public function setQueueName($queueName)
    {
        $this->queueName = $queueName;

        return $this;
    }

    public function getQueueName()
    {
        return $this->queueName;
    }

    public function setGroup($group)
    {
        if (!empty($group)) {
            $this->group = strval($group);
        } else {
            $this->group = '';
        }

        return $this;
    }

    public function getGroup()
    {
        return $this->group;
    }

    public function setFkey($fkey)
    {
        $this->fkey = $fkey;

        return $this;
    }

    public function getFkey()
    {
        return $this->fkey;
    }
}