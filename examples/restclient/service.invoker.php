<?php
function http_post_json($url, $content, $authorization)
{
    $clen = strlen($content);
    $headers = "Content-type: application/json; charset=utf-8\r\nContent-Length: {$clen}\r\nAuthorization: {$authorization}";
    $raw_context = 
    [
        'http' => ['method' => 'POST', 'header' => $headers, 'ignore_errors' => TRUE, 'content' => $content] 
        // ,'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    $context = stream_context_create($raw_context);
    $retval = ['http_status' => 'No HTTP status'];
    if (preg_match('/^https?:\/\/[^\s\/\$\.\?\#\(\)]*(\.[^\s]*)*$/', $url))
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
$response = http_post_json(trim($_POST['inputURL']), $_POST['inputData'], trim($_POST['inputToken']));
$timeOut = microtime(true);
$msPassed = round($timeOut-$timeIn, 3) * 1000;
?>
<!DOCTYPE html>
<head>
    <meta charset="utf-8"/>
</head>
<body>
    <table style="width:100%">
        <tr>
            <td style="color:red; width: 80%"><?php echo $response['http_status']; ?>, execution time <?php echo $msPassed; ?> ms</td>
            <td style="width: 20%" align="right"><input type="button" value="Clear" onclick="document.location.href='about:blank'"/></td>
        </tr>
    </table>
    <hr style="border-top: 1px solid black;"/>
    <pre>
<?php echo $response['value']; ?>
    </pre>
</body>
</html>
