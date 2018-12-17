<?php
// -------------------------------------------------------------------------------------------------
class IpCheck
{
    // -------------------------------------------------------------------------------------------------
    // IP security
    // -------------------------------------------------------------------------------------------------
	private $iplist;
	function __construct($whitelist)
	{
		$this->iplist = $whitelist;
	}

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

    public function ip_is_allowed($callerIp)
    {
        $ipIsAllowed = FALSE;
        foreach ($this->iplist as $ip)
        {
            if (self::checkIp4($callerIp, $ip))
            {
                $ipIsAllowed = TRUE;
                break;
            }
        }
        return $ipIsAllowed;
    }
}

require (__DIR__.DIRECTORY_SEPARATOR.'ipcheck.ini');
$tester = new IpCheck($iplist);

// Check IP restrictions if any
if ($argc != 2)
{
	echo 'Syntax: php ipcheck "IP address"'.PHP_EOL;
	exit(-1);
}
else
{
	echo ($tester->ip_is_allowed($argv[1]) ? 'Yes': 'No').PHP_EOL;
}

