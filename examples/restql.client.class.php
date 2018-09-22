<?php

// See example / unit test below

/**
* Simple restql client class
* S. Stefanov, 2018
*/
class RestqlClient
{
    const HEADER_LIST_DELIMITER = "\x0D\x0A";
    private static function http_post_json($url, $content, $headers = null)
    {
        if (is_null($headers)) $headers = [];
        $headers[] = ['Content-type', 'application/json; charset=utf-8'];
        $headers[] = ['Content-Length', (string)strlen($content)];
        foreach ($headers as &$header)
        {
            $header = "{$header[0]}: {$header[1]}";
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 
                                          'header' => implode(self::HEADER_LIST_DELIMITER, $headers), 
                                          'content' => $content]]);
        return file_get_contents($url, FALSE, $context);
    }

    private static function http_get($url, $headers = null)
    {
        $context_array = ['http' => ['method'  => 'GET']];
        if (!is_null($headers))
        {
            foreach ($headers as &$header)
            {
                $header = "{$header[0]}: {$header[1]}";
            }
            $context_array['http']['header'] = implode(self::HEADER_LIST_DELIMITER, $headers);
        }
        return file_get_contents($url, FALSE, stream_context_create($context_array));
    }

    // -------------------------------------------------------------------------

    private $baseUrl = NULL;
    private $accessToken = NULL;

    // -------------------------------------------------------------------------

/**
* Class constructor. Base service URL may be initialized here
* @param string $baseurl
*/
    function __construct($baseurl = NULL)
    {
        if (!is_null($baseurl))
        {
            $this -> baseUrl = $baseurl . ((substr($baseurl, -1)) == '/' ? '': '/');
        }
    }

/**
* Base URL getter/setter, returns string
* @param string $baseurl
* @return string (ex baseurl)
*/
    function baseurl($baseurl = NULL)
    {
        $retval = $this -> baseUrl;
        if (!is_null($baseurl))
        {
            $this -> baseUrl = $baseurl . ((substr($baseurl, -1)) == '/' ? '': '/');
        }
        return $retval;
    }

/**
* Access token getter/setter, returns string
* @param string $accesstoken
* @return string (ex accesstoken)
*/
    function accesstoken($accesstoken = NULL)
    {
        $retval = $this -> accessToken;
        if (!is_null($accesstoken))
        {
            $this -> accessToken = $accesstoken;
        }
        return $retval;
    }

/**
* Get service revision, returns string
* @param string $serviceName
* @return string JSON service response
*/
    function revision($serviceName)
    {
        return self::http_get($this->baseUrl.'restql.php?'.$serviceName.'/revision');
    }

/**
* Invoke the service, returns JSON string
* @param string $serviceName
* @param array $arguments Taged array of arguments' key/value pairs
* @return string JSON service response
*/
    function invoke ($serviceName, $arguments)
    {
        return self::http_post_json($this->baseUrl.'restql.php?'.$serviceName, json_encode((object)$arguments), [['Authorization', $this -> accessToken]]);
    }
}

/*
CLI example / unit test

 define ('BASEURL', 'https://clients.mbm-express.net/restsvc');
 define ('ACCESSTOKEN', 'PTn456KSqqU7WhSszSe');
 
 $restql = new RestqlClient(BASEURL);
 $restql -> accesstoken(ACCESSTOKEN);
 
 echo $restql -> baseurl().PHP_EOL;
 echo $restql -> accesstoken().PHP_EOL;
 echo $restql -> revision('demo').PHP_EOL;
 echo $restql -> invoke('demo', ['lower_limit' => 28, 'label' => 'lorem ipsum']).PHP_EOL;
*/