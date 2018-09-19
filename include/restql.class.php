<?php

// Restql
// Query-to-RESTful-service generator
// S.Stefanov, Feb 2017 - Aug 2018

class Restql
{
    const   RESULT_SET_RESPONSE = 'table',
            SINGLE_VALUE_RESPONSE = 'value',
            SINGLE_RECORD_RESPONSE = 'row',
            VOID_RESPONSE = 'void';

    const   JSON_MODE = 0x180; // JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE, 0x80|0x100;
    const   HTTP_STATUS_NOT_FOUND = 404, HTTP_STATUS_OK = 200;
    const   REVISION_XR = '/^(\w+)\/revision$/';

    private $serviceFilesLocation;
    private $loggerSql;
    private $connectionString;

    // -------------------------------------------------------------------------------------------------
    // Generic helpers
    // -------------------------------------------------------------------------------------------------
    private static function log($s)
    {
        $logFileName = __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'debug.log.txt';
        file_put_contents($logFileName, date('Y-m-d H:i:s').': '.var_export($s, TRUE).PHP_EOL, FILE_APPEND);
    }

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
    private static function to_utf8($s)
    {
      return iconv('CP1251', 'UTF-8', $s);
    }

    // -------------------------------------------------------------------------------------------------
    // Helper - senf JSON response and exit
    // -------------------------------------------------------------------------------------------------
    private static function json_response($contents, $status = TRUE, $extra_payload = FALSE)
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
    private static function service_error($errText = 'NO SERVICE')
    {
        self::json_response($errText, false);
    }

    // -------------------------------------------------------------------------------------------------
    // Helper - get caller IP address
    // -------------------------------------------------------------------------------------------------
    private static function get_caller_ip()
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
    // Validate/fix arguments' set against configuration
    // -------------------------------------------------------------------------------------------------
    private static function manage_arguments(&$target, $config_arguments)
    {
        foreach($config_arguments AS $argument_name => $argument_settings)
        {
            $argument_settings = (array) $argument_settings;

            if (!array_key_exists($argument_name, $target))
            {
                if (array_key_exists('constant', $argument_settings))
                {
                    $target[$argument_name] = $argument_settings['constant'];
                }
                elseif (array_key_exists('default', $argument_settings))
                {
                    $target[$argument_name] = $argument_settings['default'];
                }
                else
                {
                    self::log($argument_name.' missing');
                    return FALSE;
                }
            }
            elseif (array_key_exists('constant', $argument_settings))
            {
                self::log($argument_name.' constant override');
                return FALSE;
            }

            if (array_key_exists('type', $argument_settings))
            switch ($argument_settings['type'])
            {
                case 'number':
                if (!is_numeric($target[$argument_name]) && !is_null($target[$argument_name]))
                {
                    self::log($argument_name.' type mismatch');
                    return FALSE;
                }
                break;

                case 'boolean':
                if (!is_bool($target[$argument_name]) && !is_null($target[$argument_name]))
                {
                    self::log($argument_name.' type mismatch');
                    return FALSE;
                }

                // PDO has problems with boolean parameters
                if (is_bool($target[$argument_name]))
                    $target[$argument_name] = $target[$argument_name] ? 1: 0;

                break;

                case 'text':
                if (array_key_exists('pattern', $argument_settings) && !is_null($target[$argument_name]))
                {
                    if (!preg_match($argument_settings['pattern'], $target[$argument_name]))
                    {
                        self::log($argument_name.' pattern mismatch');
                        return FALSE;
                    }
                }
                break;

                default:
                    self::log('Unsupported argument type: '.$argument_settings['type']);
                    self::service_error();
            }
            else
            {
                self::log('No argument type for '.$argument_name);
                self::service_error();
            }
        }

        foreach($target AS $targetKey => $targetValue)
        {
            if (!array_key_exists($targetKey, $config_arguments))
            {
                self::log('Runtime argument '.$targetKey.' one too many');
                return FALSE;
            }
        }

        return TRUE;
    }

    // -------------------------------------------------------------------------------------------------
    // IP security
    // -------------------------------------------------------------------------------------------------
    private static function ip_is_allowed($callerIp, $iplist)
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
    // Post-processing
    // -------------------------------------------------------------------------------------------------
    private static function servicePostProcess($postProcessFileName, &$args, &$response, $conn = NULL)
    {
        $retval = FALSE;
        if ($postProcessFileName && file_exists($postProcessFileName))
        {
            $errMessage = null;
            ob_start();
            try
            {
                include($postProcessFileName);
                $retval = postProcess($args, $response, $conn);
            }
            catch (Exception $ignored)
            {
                $errMessage = $ignored -> getMessage();
            };
            ob_end_clean();
            if (!is_null($errMessage)) self::log($errMessage);
        }
        return $retval;
    }

    // -------------------------------------------------------------------------------------------------
    public function __construct($instanceFilesLocation, $serviceFilesLocation)
    {
        $this->serviceFilesLocation = $serviceFilesLocation.DIRECTORY_SEPARATOR;
        $connectionFileName = $instanceFilesLocation.DIRECTORY_SEPARATOR.'db.connection.config';
        $loggerSqlFileName  = $instanceFilesLocation.DIRECTORY_SEPARATOR.'logger.sql.config';

        mb_regex_encoding('UTF-8');
        $this->connectionString = file_get_contents($connectionFileName);
        $this->loggerSql = (file_exists($loggerSqlFileName)) ? file_get_contents($loggerSqlFileName): FALSE;
    }

    // -------------------------------------------------------------------------------------------------
    public function handle()
    {
        $rawArguments = file_get_contents('php://input');
        $resourceName = $_SERVER['QUERY_STRING'];
        $callerIp = self::get_caller_ip();

        // Check for a revision call
        $revisionResource = [];
        if (preg_match(self::REVISION_XR, $resourceName, $revisionResource))
        {
            $resourceName = $revisionResource[1];
        }

        $resourceIniFileName = $this->serviceFilesLocation.$resourceName.'.config.json';

        // If a revision call then handle early and leave quickly
        if ($revisionResource)
        {
            $revisionTime = filemtime($resourceIniFileName);
            self::json_response($revisionTime);
        }

        // Parse service definition
        if (!file_exists($resourceIniFileName))
        {
            self::log('File .config.json missing');
            self::service_error();
        }

        $cfg_text = file_get_contents($resourceIniFileName);
        $cfg = json_decode($cfg_text);
        $cfg_settings = (array) $cfg -> settings;
        $cfg_arguments = (array) $cfg -> arguments;

        // Check mandatory configuration settings
        if (!array_key_exists('query', $cfg_settings) || !file_exists($resourceSqlFileName = $this->serviceFilesLocation.$cfg_settings['query']) ||
            !array_key_exists('token', $cfg_settings) || !array_key_exists('response', $cfg_settings) ||
            !in_array($cfg_settings['response'], [self::RESULT_SET_RESPONSE, self::SINGLE_VALUE_RESPONSE, self::SINGLE_RECORD_RESPONSE, self::VOID_RESPONSE]))
        {
            self::log('Mandatory configuration attribute(s) missing or invalid');
            self::service_error();
        }

        // Check IP restrictions if any
        if (array_key_exists('iplist', $cfg_settings) && !self::ip_is_allowed($callerIp, $cfg_settings['iplist']))
        {
            self::log('Rejected '.$callerIp);
            self::service_error();
        }

        // Sort out post-processing
        $postProcessFileName = (array_key_exists('postprocess', $cfg_settings) && $cfg_settings['postprocess']) ? $this->serviceFilesLocation.$cfg_settings['postprocess']: FALSE;

        // Sort out arguments and check token security
        $args = (array) json_decode($rawArguments);
        $headers = getallheaders();
        if (!array_key_exists('Authorization', $headers) || ($headers['Authorization'] != $cfg_settings['token']) || !self::manage_arguments($args, $cfg_arguments))
        {
            self::service_error('Arguments error');
        }

        try
        {
            $sql = file_get_contents($resourceSqlFileName);
            $conn = new PDO($this->connectionString);
            $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, FALSE);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if ($this->loggerSql)
            {
                $prs = $conn->prepare($this->loggerSql);
                $prs->execute([':call_by' => $callerIp, ':call_resource' => $resourceName, ':call_payload' => $rawArguments]);
            }
            $prs = $conn->prepare($sql);
            $sqlArgs = [];
            foreach($args AS $argsKey => $argsValue)
            {
                $sqlArgs[':'.$argsKey] = $argsValue;
            }
            $prs->execute($sqlArgs);
            switch ($cfg_settings['response'])
            {
                case (self::SINGLE_RECORD_RESPONSE):
                    $response = $prs->fetch(PDO::FETCH_ASSOC);
                    $extra = self::servicePostProcess($postProcessFileName, $args, $response, $conn);
                    self::json_response($response, TRUE, $extra);
                    break;
                case (self::RESULT_SET_RESPONSE):
                    $response = $prs->fetchAll(PDO::FETCH_ASSOC);
                    $extra = self::servicePostProcess($postProcessFileName, $args, $response, $conn);
                    self::json_response($response, TRUE, $extra);
                    break;
                case (self::SINGLE_VALUE_RESPONSE):
                    $response = $prs->fetchColumn();
                    $extra = self::servicePostProcess($postProcessFileName, $args, $response, $conn);
                    self::json_response($response, TRUE, $extra);
                    break;
                case (self::VOID_RESPONSE):
                    self::json_response('OK', TRUE);
            }
        }
        catch (Exception $err)
        {
            self::log('Runtime: '.$err->getMessage());
            self::service_error('Runtime error');
        }
    }
}
