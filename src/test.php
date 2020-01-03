<?php

require_once('./vendor/autoload.php');

use Walle\Modules\Notify\NotifyAgent;
use Walle\Modules\Notify\NotifyMessage;

for ($i = 1; $i <= 10; $i++) {
    $message = NotifyMessage::create("http://a.demo.test")
                            ->setData(['id' => $i, 'name' => 'zq'.$i])
                            ->setMethod('POST');
    if ($i % 2 === 0) {
        $message->setSendTime(date('Y-m-d H:i:s', strtotime('+ 1 minutes')));
        $message->setContentType(NotifyMessage::CONTENT_TYPE_JSON);
    } else {
        $message->setContentType(NotifyMessage::CONTENT_TYPE_FORM);
    }

    NotifyAgent::getInstance()->addNotify($message);
}
