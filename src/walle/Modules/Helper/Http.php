<?php
/**
 * Walle\Modules\Helper\Http
 *
 * @author     <dendi875@163.com>
 * @createDate 2020-01-03 15:01:54
 * @copyright  Copyright (c) 2019 https://github.com/dendi875
 */

namespace Walle\Modules\Helper;

class Http
{
    const CONTENT_TYPE_FORM = 'application/x-www-form-urlencoded';
    const CONTENT_TYPE_JSON = 'application/json';

    private static $errno = 0;
    private static $errmsg = '';

    public static function curlRequest($url, $data, $method = 'POST', $timeoutMilli = 60000, $contentType)
    {
        $ch = curl_init();

        if ($timeoutMilli > 0) {
            set_time_limit(ceil($timeoutMilli / 1000));
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMilli);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        $urlArr = parse_url($url);

        if (strtolower($urlArr['scheme']) === 'https') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }

        if ($urlArr['port']) {
            curl_setopt($ch, CURLOPT_PORT, $urlArr['port']);
        }

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($contentType == static::CONTENT_TYPE_JSON) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: ' . static::CONTENT_TYPE_JSON,
                    'Content-Length: ' . strlen($data)
                ]);
            } else {
                $data = (is_array($data)) ? http_build_query($data) : $data;
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        } else { //GET method
            if (is_string($data)) {
                $url .= (false === strpos($url, '?')) ? '?'.$data : '&'.$data;
            } elseif (is_array($data)) {
                $url .= (false === strpos($url, '?')) ? '?'.http_build_query($data) : '&'.http_build_query($data);
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        $response = curl_exec($ch);

        static::$errno = curl_errno($ch);
        static::$errmsg = curl_error($ch);

        curl_close($ch);

        return $response;
    }


    public static function getErrno()
    {
        return static::$errno;
    }

    public static function getErrmsg()
    {
        return static::$errmsg;
    }
}