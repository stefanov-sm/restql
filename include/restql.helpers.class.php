<?php

class RestqlHelpers
{
    const   JSON_MODE = 0x180; // JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE, 0x80|0x100;
    const   HTTP_STATUS_NOT_FOUND = 404, HTTP_STATUS_OK = 200;

    // -------------------------------------------------------------------------------------------------
    // Generic helpers
    // -------------------------------------------------------------------------------------------------

    static function log($s)
    {
        $logFileName = __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'debug.log.txt';
        $eventDate = date('Y-m-d H:i:s');
        $event_message = var_export($s, TRUE);
        $referer_ip = self::get_caller_ip();
        file_put_contents($logFileName, "{$eventDate}, referer {$referer_ip}, service '{$_SERVER['QUERY_STRING']}': {$event_message}".PHP_EOL, FILE_APPEND);
    }

    // -------------------------------------------------------------------------------------------------
    // Helper - get caller IP address
    // -------------------------------------------------------------------------------------------------
    static function get_caller_ip()
    {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'] as $key)
        {
            if (array_key_exists($key, $_SERVER))
            {
                foreach (explode(',', $_SERVER[$key]) as $ip)
                {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false)
                    {
                        return $ip;
                    }
                }
            }
        }
        // If none of the above
        return $_SERVER['REMOTE_ADDR'];
    }

    // -------------------------------------------------------------------------------------------------
    // Helper - decode JSON error
    // -------------------------------------------------------------------------------------------------
    static function decode_json_error($err)
    {
      $decode_table =
      [
        0  => 'JSON_ERROR_NONE',
        1  => 'JSON_ERROR_DEPTH',
        2  => 'JSON_ERROR_STATE_MISMATCH',
        3  => 'JSON_ERROR_CTRL_CHAR',
        4  => 'JSON_ERROR_SYNTAX',
        5  => 'JSON_ERROR_UTF8',
        6  => 'JSON_ERROR_RECURSION',
        7  => 'JSON_ERROR_INF_OR_NAN',
        8  => 'JSON_ERROR_UNSUPPORTED_TYPE',
        9  => 'JSON_ERROR_INVALID_PROPERTY_NAME',
        10 => 'JSON_ERROR_UTF16'
      ];
      return $decode_table[$err];
    }

    // -------------------------------------------------------------------------------------------------
    // Helper - check if IP4 address is within a range
    // -------------------------------------------------------------------------------------------------
    private static function checkIp4($requestIp, $ip)
    {
        if (!filter_var($requestIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
        {
            return false;
        }
        if (false !== strpos($ip, '/'))
        {
            list($address, $netmask) = explode('/', $ip, 2);
            if ($netmask === '0')
            {
                return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            }
            if ($netmask < 0 || $netmask > 32)
            {
                return false;
            }
        }
        else
        {
            $address = $ip;
            $netmask = 32;
        }
        return (substr_compare(sprintf('%032b', ip2long($requestIp)), sprintf('%032b', ip2long($address)), 0, $netmask) === 0);
    }

    // -------------------------------------------------------------------------------------------------
    // Helper - check if an IP4 address is in a whitelist
    // -------------------------------------------------------------------------------------------------
    static function ip_is_allowed($callerIp, $iplist)
    {
        $ipIsAllowed = FALSE;
        foreach ($iplist as $ip)
        {
            if (self::checkIp4($callerIp, $ip))
            {
                $ipIsAllowed = TRUE;
                break;
            }
        }
        return $ipIsAllowed;
	}

    // -------------------------------------------------------------------------------------------------
    // Helper - send JSON response and exit
    // -------------------------------------------------------------------------------------------------
    static function json_response($contents, $status = TRUE, $extra_payload = FALSE)
    {
        $response = (object)[];
        $response -> status = $status;
        $response -> data = $contents;
        if ($status && $extra_payload)
        {
            $response -> extra = $extra_payload;
        }
        header('Connection: Close');
        header('Content-Type: application/json; charset=utf-8');

        http_response_code($status ? self::HTTP_STATUS_OK: self::HTTP_STATUS_NOT_FOUND);
        echo json_encode($response, self::JSON_MODE);
        exit();
    }

    // -------------------------------------------------------------------------------------------------
    // Helper - respond with an error
    // -------------------------------------------------------------------------------------------------
    static function service_error($errText = 'NO SERVICE')
    {
        self::json_response($errText, false);
    }

    // -------------------------------------------------------------------------------------------------
    static function to_utf8($s) // Not used. Have it in place for just in case
    {
      return iconv('CP1251', 'UTF-8', $s);
    }
}