<?php
function http_post_json($url, $content, $authorization)
{
    $clen = strlen($content);
    $headers = "Content-type: application/json; charset=utf-8\r\nContent-Length: {$clen}\r\nAuthorization: {$authorization}";
    $raw_context = 
    [
        'http' => ['method' => 'POST', 'header' => $headers, 'ignore_errors' => TRUE, 'content' => $content] 
        ,'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    $context = stream_context_create($raw_context);
    $retval = ['http_status' => 'No HTTP status'];
    if (!empty($url = filter_var($url, FILTER_VALIDATE_URL)))
    {
        $retval['value'] = @ file_get_contents($url, FALSE, $context);
        if (!$retval['value'])
        {
            $retval['value'] = 'No response, probably bad URL given';
        }
        else
        {
            $retval['http_status'] = $http_response_header[0];
        }
    }
    else
    {
        $retval['value'] = 'No valid URL given';
    }
    return $retval;
}

function http_get_json($url, $authorization)
{
    $headers = "Authorization: {$authorization}";
    $raw_context = 
    [
        'http' => ['method' => 'GET', 'header' => $headers, 'ignore_errors' => TRUE] 
        ,'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    $context = stream_context_create($raw_context);
    $retval = ['http_status' => 'No HTTP status'];
    if (!empty($url = filter_var($url, FILTER_VALIDATE_URL)))
    {
        $retval['value'] = @ file_get_contents($url, FALSE, $context);
        if (!$retval['value'])
        {
            $retval['value'] = 'No response, probably bad URL given';
        }
        else
        {
            $retval['http_status'] = $http_response_header[0];
        }
    }
    else
    {
        $retval['value'] = 'No valid URL given';
    }
    return $retval;
}

$timeIn = microtime(true);

if (array_key_exists('isPost', $_POST) && ($_POST['isPost'] === 'on'))
{
	$response = http_post_json(trim($_POST['inputURL']), $_POST['inputData'], trim($_POST['inputToken']));
	$method = 'POST';
}
else	
{
	$response = http_get_json(trim($_POST['inputURL']), trim($_POST['inputToken']));
	$method = 'GET';
}
$timeOut = microtime(true);
$msPassed = round($timeOut-$timeIn, 3) * 1000;

header('Cache-Control: no-cache, no-store, must-revalidate');

?>
<!DOCTYPE html>
<head>
    <meta charset="utf-8"/>
    <meta http-equiv='cache-control' content='no-cache'>
	<meta http-equiv='expires' content='0'>
	<meta http-equiv='pragma' content='no-cache'>
</head>
<body>
    <table style="width:100%">
        <tr>
            <td style="color:red; width: 80%"><?php echo "$method: {$response['http_status']}, execution time $msPassed ms" ?></td>
            <td style="width: 20%" align="right"><input type="image" src="Clear.png" style="width:48px" onclick="document.location.href='about:blank'" title="Clear result pane"></td>
        </tr>
    </table>
    <hr>
    <pre>
<?php echo $response['value']; ?>
    </pre>
</body>
</html>
