<?php

require_once('./vendor/autoload.php');

use Walle\Modules\Notify\NotifyAgent;
use Walle\Modules\Notify\NotifyMessage;


/*
// 一、测试立即发送通知
$msg1 = NotifyMessage::create("http://a.demo.test")
                        ->setData(['id' => 1, 'name' => 'zq1'])
                        ->setMethod('POST')
                        ->setContentType(NotifyMessage::CONTENT_TYPE_JSON);

NotifyAgent::getInstance()->addNotify($msg1);


$msg2 = NotifyMessage::create("http://a.demo.test")
                        ->setData(['id' => 2, 'name' => 'zq2'])
                        ->setMethod('POST')
                        ->setContentType(NotifyMessage::CONTENT_TYPE_FORM);

NotifyAgent::getInstance()->addNotify($msg2);


$msg3 = NotifyMessage::create("http://a.demo.test")
                        ->setData(['id' => 3, 'name' => 'zq3'])
                        ->setMethod('GET')
                        ->setContentType(NotifyMessage::CONTENT_TYPE_FORM);

NotifyAgent::getInstance()->addNotify($msg3);*/


/*// 二、测试延迟发送通知
$msg1 = NotifyMessage::create("http://a.demo.test")
    ->setData(['id' => 1, 'name' => 'zq1'])
    ->setMethod('POST')
    ->setContentType(NotifyMessage::CONTENT_TYPE_JSON)
    ->setSendTime(date('Y-m-d H:i:s', strtotime('+1 minutes')));

NotifyAgent::getInstance()->addNotify($msg1);


$msg2 = NotifyMessage::create("http://a.demo.test")
    ->setData(['id' => 2, 'name' => 'zq2'])
    ->setMethod('POST')
    ->setContentType(NotifyMessage::CONTENT_TYPE_FORM)
    ->setSendTime(date('Y-m-d H:i:s', strtotime('+3 minutes')));

NotifyAgent::getInstance()->addNotify($msg2);


$msg3 = NotifyMessage::create("http://a.demo.test")
    ->setData(['id' => 3, 'name' => 'zq3'])
    ->setMethod('GET')
    ->setContentType(NotifyMessage::CONTENT_TYPE_FORM)
    ->setSendTime(date('Y-m-d H:i:s', strtotime('+5 minutes')));

NotifyAgent::getInstance()->addNotify($msg3);*/




/*
// 三、测试通过 fkey 创建通知，然后等待外部触发
$msg1 = NotifyMessage::create("http://a.demo.test")
                        ->setData(['id' => 1, 'name' => 'zq1'])
                        ->setMethod('POST')
                        ->setContentType(NotifyMessage::CONTENT_TYPE_JSON)
                        ->setSendImmediate(false)
                        ->setFKey('f1');

NotifyAgent::getInstance()->addNotify($msg1);

NotifyAgent::getInstance()->triggerNotify('f1', ['age' => 28]);*/




/*
// 四、测试不同地址通知是并发执行的
$msg1 = NotifyMessage::create("http://a.demo.test")
    ->setData(['id' => 1, 'name' => 'zq1'])
    ->setMethod('POST')
    ->setContentType(NotifyMessage::CONTENT_TYPE_JSON);

NotifyAgent::getInstance()->addNotify($msg1);


$msg2 = NotifyMessage::create("http://b.demo.test")
    ->setData(['id' => 2, 'name' => 'zq2'])
    ->setMethod('POST')
    ->setContentType(NotifyMessage::CONTENT_TYPE_FORM);

NotifyAgent::getInstance()->addNotify($msg2);*/



// 五、测试相同地址通知也是可以并发执行的
$msg1 = NotifyMessage::create("http://a.demo.test")
    ->setData(['id' => 1, 'name' => 'zq1'])
    ->setMethod('POST')
    ->setContentType(NotifyMessage::CONTENT_TYPE_JSON)
    ->setGroup(1);

NotifyAgent::getInstance()->addNotify($msg1);


$msg2 = NotifyMessage::create("http://a.demo.test")
    ->setData(['id' => 2, 'name' => 'zq2'])
    ->setMethod('POST')
    ->setContentType(NotifyMessage::CONTENT_TYPE_FORM)
    ->setGroup(2);

NotifyAgent::getInstance()->addNotify($msg2);