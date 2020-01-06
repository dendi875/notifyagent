<?php

require_once('./vendor/autoload.php');

use Walle\Modules\Notify\NotifyAgent;
use Walle\Modules\Notify\NotifyMessage;



// 测试通过 fkey 创建通知，然后等待外部触发
$msg1 = NotifyMessage::create("http://a.demo.test")
                        ->setData(['id' => 1, 'name' => 'zq1'])
                        ->setMethod('POST')
                        ->setContentType(NotifyMessage::CONTENT_TYPE_JSON)
                        ->setSendImmediate(false)
                        ->setFKey('f1');

NotifyAgent::getInstance()->addNotify($msg1);


$msg2 = NotifyMessage::create("http://a.demo.test")
                        ->setData(['id' => 2, 'name' => 'zq2'])
                        ->setMethod('POST')
                        ->setContentType(NotifyMessage::CONTENT_TYPE_FORM)
                        ->setSendImmediate(false)
                        ->setFKey('f2');
NotifyAgent::getInstance()->addNotify($msg2);


$msg3 = NotifyMessage::create("http://a.demo.test")
                        ->setData(['id' => 3, 'name' => 'zq3'])
                        ->setMethod('GET')
                        ->setContentType(NotifyMessage::CONTENT_TYPE_FORM)
                        ->setSendImmediate(false)
                        ->setFKey('f3');
NotifyAgent::getInstance()->addNotify($msg3);


// 触发通知
NotifyAgent::getInstance()->triggerNotify('f1', ['age' => 28]);
NotifyAgent::getInstance()->triggerNotify('f2', ['age' => 29]);
NotifyAgent::getInstance()->triggerNotify('f3', ['age' => 29]);