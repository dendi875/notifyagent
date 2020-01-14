CREATE DATABASE notifyagent CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

CREATE TABLE `Notify` (
  `id` bigint(10) unsigned NOT NULL AUTO_INCREMENT,
  `fKey` varchar(150) DEFAULT NULL COMMENT '外键',
  `queueName` varchar(100) NOT NULL DEFAULT '' COMMENT '队列名称',
  `caller` varchar(100) NOT NULL DEFAULT '' COMMENT '调用者',
  `url` varchar(254) NOT NULL DEFAULT '' COMMENT 'http url',
  `method` varchar(10) NOT NULL DEFAULT '' COMMENT 'http method',
  `contentType` varchar(100) NOT NULL DEFAULT '' COMMENT 'http content-type',
  `data` text NOT NULL COMMENT '请求数据',
  `needResponse` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '是否需要应答 1是，2否',
  `expectResponse` varchar(100) NOT NULL DEFAULT '' COMMENT '预期的应答',
  `actualResponse` varchar(100) NOT NULL DEFAULT '' COMMENT '实际的应答',
  `runOnce` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '是否只执行一次 1是，2否',
  `retryTimes` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '重试次数',
  `timeStartSend` datetime DEFAULT NULL COMMENT '开始发送时间',
  `timeDelayedSend` datetime DEFAULT NULL COMMENT '延时发送时间',
  `timeCreated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `timeModified` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后修改时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fkey` (`fKey`),
  KEY `idx_timeDelayedSend` (`timeDelayedSend`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='延迟通知';

