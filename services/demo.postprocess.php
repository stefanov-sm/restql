<?php
function postProcess($args, $response_data, $dbConn)
{
	$retval = (object)[];
	$retval -> args = $args;
	$retval -> response_data_count = count($response_data);
	$retval -> server_status =  $dbConn->getAttribute(PDO::ATTR_CONNECTION_STATUS);
	return $retval;
}