<?php

class ResponseException extends Exception {}

/**
 * Build the response array that gets sent as a JSON response.
 *
 * Array contents:
 * code = Return code, maps for http codes
 * message = User friendly message
 * op = Operation used for this response
 * data = Array of data, contents is op specific
 *
 */
class Response
{


public static function data($code, $message, $op, array $data=null)
{
$meta=array('code'=>$code, 'message'=>$message, 'op'=>$op);
return array(
	'version'=>1,
	'code'=>$code,
	'message'=>$message,
	'op'=>$op,
	'meta'=>$meta,
	'data'=>$data);
}

public static function error($code, $error, $op)
{
$meta=array('code'=>$code, 'error'=>$error, 'op'=>$op);
return array(
	'version'=>1,
	'code'=>$code,
	'error'=>$error,
	'op'=>$op,
	'meta'=>$meta);
}

} // class
