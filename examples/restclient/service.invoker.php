<?php
function http_post_json($url, $content, $authorization)
{
	$content_length = strlen($content);
    $headers = <<<HEADERS
Content-type: application/json; charset=utf-8
Content-Length: {$content_length}
Authorization: {$authorization}
HEADERS;

    $raw_context = ['http' => ['method' => 'POST', 'header' => $headers, 'ignore_errors' => TRUE, 'content' => $content]];
    $context = stream_context_create($raw_context);
    $retval = [];
    if (trim($url))
    {
    	$retval['value'] = @ file_get_contents($url, FALSE, $context);
    	$retval['http_status'] = $http_response_header[0];
    }
    else
    {
    	$retval['value'] = 'No URL given';
    	$retval['http_status'] = 'No HTTP status';
    }
    
    return (object)$retval;
}


$timeIn = microtime(true);
$response = http_post_json($_POST['inputURL'], $_POST['inputData'],trim($_POST['inputToken']));
$timeOut = microtime(true);
$msPassed = round($timeOut-$timeIn, 3) * 1000;

echo <<<HTML
<!DOCTYPE html>
<head>
	<meta charset="utf-8"/>
</head>
<body>
	<table style="width:100%">
		<tr>
			<td style="color:red; width: 80%">{$response->http_status}, execution time {$msPassed} ms</td>
			<td style="width: 20%" align="right"><input type="button" value="Clear" onclick="document.location.href='about:blank'" ></td>
		</tr>
	</table>
	<hr>
	<pre>
{$response->value}
	</pre>
</body>
</html>
HTML;
