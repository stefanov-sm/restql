<?php
// Restql query-to-RESTful-service generator
// S.Stefanov, Feb 2017 - Jul 2019

require (__DIR__.DIRECTORY_SEPARATOR.'restql.helpers.class.php');
class Restql
{
    const   RESULT_SET_RESPONSE = 'table', SINGLE_VALUE_RESPONSE = 'value', JSON_VALUE_RESPONSE = 'jsonvalue', SINGLE_RECORD_RESPONSE = 'row', VOID_RESPONSE = 'void';
    const   REVISION_XR = '/^(\w+)\/revision$/';

    private $serviceFilesLocation, $loggerSql, $connectionString, $dbAccount;

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
                    RestqlHelpers::log($argument_name.' missing');
                    return FALSE;
                }
            }
            elseif (array_key_exists('constant', $argument_settings))
            {
                RestqlHelpers::log($argument_name.' constant override');
                return FALSE;
            }

            if (array_key_exists('type', $argument_settings))
            switch ($argument_settings['type'])
            {
                case 'number':
                if (!is_numeric($target[$argument_name]) && !is_null($target[$argument_name]))
                {
                    RestqlHelpers::log($argument_name.' type mismatch');
                    return FALSE;
                }
                break;

                case 'boolean':
                if (!is_bool($target[$argument_name]) && !is_null($target[$argument_name]))
                {
                    RestqlHelpers::log($argument_name.' type mismatch');
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
                        RestqlHelpers::log($argument_name.' pattern mismatch');
                        return FALSE;
                    }
                }
                break;

                default:
                    RestqlHelpers::log('Unsupported argument type: '.$argument_settings['type']);
                    RestqlHelpers::service_error();
            }
            else
            {
                RestqlHelpers::log('No argument type for '.$argument_name);
                RestqlHelpers::service_error();
            }
        }

        foreach($target AS $targetKey => $targetValue)
        {
            if (!array_key_exists($targetKey, $config_arguments))
            {
                RestqlHelpers::log('Runtime argument '.$targetKey.' one too many');
                return FALSE;
            }
        }

        return TRUE;
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
            if (!is_null($errMessage)) RestqlHelpers::log($errMessage);
        }
        return $retval;
    }

    // -------------------------------------------------------------------------------------------------
    public function __construct($instanceFilesLocation, $serviceFilesLocation)
    {
        $this->serviceFilesLocation = $serviceFilesLocation.DIRECTORY_SEPARATOR;
        $connectionFileName = $instanceFilesLocation.DIRECTORY_SEPARATOR.'db.connection.config';
        $accountFileName    = $instanceFilesLocation.DIRECTORY_SEPARATOR.'db.account.config';
        $loggerSqlFileName  = $instanceFilesLocation.DIRECTORY_SEPARATOR.'logger.sql.config';

        $this->connectionString = file_get_contents($connectionFileName);
        $this->dbAccount = (file_exists($accountFileName)) ? json_decode(file_get_contents($accountFileName)): FALSE;
        $this->loggerSql = (file_exists($loggerSqlFileName)) ? file_get_contents($loggerSqlFileName): FALSE;
    }

    // -------------------------------------------------------------------------------------------------
    public function handle()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            $rawArguments = file_get_contents('php://input');
        }
        else
        {
            $rawArguments = '{}';
        }

        $resourceName = $_SERVER['QUERY_STRING'];
        $callerIp = RestqlHelpers::get_caller_ip();

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
            $revisionTime = @ filemtime($resourceIniFileName);
            RestqlHelpers::json_response($revisionTime);
        }

        // Parse service definition
        if (!file_exists($resourceIniFileName))
        {
            RestqlHelpers::log('File .config.json missing');
            RestqlHelpers::service_error();
        }

        $cfg_text = file_get_contents($resourceIniFileName);
        $cfg = json_decode($cfg_text);
        $cfg_settings = (array) $cfg -> settings;
        $cfg_arguments = (array) $cfg -> arguments;

        // Check mandatory configuration settings
        if (!array_key_exists('query', $cfg_settings) || !file_exists($resourceSqlFileName = $this->serviceFilesLocation.$cfg_settings['query']) ||
            !array_key_exists('token', $cfg_settings) || !array_key_exists('response', $cfg_settings) ||
            !in_array($cfg_settings['response'], 
            	[
            	 self::RESULT_SET_RESPONSE, self::SINGLE_VALUE_RESPONSE, 
            	 self::SINGLE_RECORD_RESPONSE, self::VOID_RESPONSE, self::JSON_VALUE_RESPONSE
            	])
           )
        {
            RestqlHelpers::log('Mandatory configuration attribute(s) missing or invalid');
            RestqlHelpers::service_error();
        }

        // Check IP restrictions if any
        if (array_key_exists('iplist', $cfg_settings) && !RestqlHelpers::ip_is_allowed($callerIp, $cfg_settings['iplist']))
        {
            RestqlHelpers::log('Rejected '.$callerIp);
            RestqlHelpers::service_error();
        }

        // Sort out post-processing
        $postProcessFileName = (array_key_exists('postprocess', $cfg_settings) && $cfg_settings['postprocess']) ? $this->serviceFilesLocation.$cfg_settings['postprocess']: FALSE;

        // Sort out arguments and check token security
        $args = (array) json_decode($rawArguments);
        
        $json_error = json_last_error();
        if ($json_error !== JSON_ERROR_NONE)
        {
            RestqlHelpers::log('Call arguments JSON error: ' . RestqlHelpers::decode_json_error($json_error));
            RestqlHelpers::service_error('Arguments error');
        }
        
        $headers = getallheaders();
        if (!array_key_exists('Authorization', $headers) || ($headers['Authorization'] != $cfg_settings['token']))
        {
            RestqlHelpers::log('Authorization error');
            RestqlHelpers::service_error('Arguments error');
        }

        if (!self::manage_arguments($args, $cfg_arguments))
        {
            RestqlHelpers::service_error('Arguments error');
        }

        try
        {
            $sql = file_get_contents($resourceSqlFileName);
            if ($this->dbAccount)
            {
            	$conn = new PDO($this->connectionString, $this->dbAccount->username, $this->dbAccount->password);
            }
            else
            {           
            	$conn = new PDO($this->connectionString);
            }
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
                    RestqlHelpers::json_response($response, TRUE, $extra);
                    break;
                case (self::RESULT_SET_RESPONSE):
                    $response = $prs->fetchAll(PDO::FETCH_ASSOC);
                    $extra = self::servicePostProcess($postProcessFileName, $args, $response, $conn);
                    RestqlHelpers::json_response($response, TRUE, $extra);
                    break;
                case (self::SINGLE_VALUE_RESPONSE):
                    $response = $prs->fetchColumn();
                    $extra = self::servicePostProcess($postProcessFileName, $args, $response, $conn);
                    RestqlHelpers::json_response($response, TRUE, $extra);
                    break;
                case (self::JSON_VALUE_RESPONSE):
                    $response = $prs->fetchColumn();
                    $extra = self::servicePostProcess($postProcessFileName, $args, $response, $conn);
                    RestqlHelpers::json_response(json_decode($response), TRUE, $extra);
                    break;
                case (self::VOID_RESPONSE):
                    $response = null;
                    $extra = self::servicePostProcess($postProcessFileName, $args, $response, $conn);
                    RestqlHelpers::json_response('OK', TRUE, $extra);
            }
        }
        catch (Exception $err)
        {
            RestqlHelpers::log('Runtime: '.$err->getMessage());
            RestqlHelpers::service_error('Runtime error');
        }
    }
}