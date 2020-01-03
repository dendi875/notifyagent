<?php
/**
 * Walle\Modules\Process\Process
 *
 * @author     <quan.zhang@guanaitong.com>
 * @createDate 2019-12-24 16:12:55
 * @copyright  Copyright (c) 2019 guanaitong.com
 */

namespace Walle\Modules\Process;

class Process
{
    /**
     * 返回进程退出时的状态信息
     *
     * @param $status
     * @return string
     */
    public static function getExitStatus($status)
    {
        $exitMsg = '';

        if (pcntl_wifexited($status)) {
            $exitMsg = sprintf("normal termination, exit status = %d", pcntl_wexitstatus($status));
        } else if (pcntl_wifsignaled($status)) {
            $exitMsg = sprintf("abnormal termination, signal number = %d", pcntl_wtermsig($status));
        } else if (pcntl_wifstopped($status)) {
            $exitMsg = sprintf("child stoped, signal number = %d", pcntl_wstopsig($status));
        }

        return $exitMsg;
    }

    /**
     * 获取进程的各个 ID 值。进程ID、父进程ID、进程组ID、会话ID
     * @param $name
     * @return string
     */
    public static function getPids($name)
    {
        $pid = posix_getpid();

        $pids = sprintf("%s：pid = %d, ppid = %d, pgid = %d, sid = %d",
            $name, $pid, posix_getppid(), posix_getpgid($pid), posix_getsid($pid));

        return $pids;
    }
}