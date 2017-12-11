<?php

function slog($er, $data='', Exception $e=null)
{
openlog("rvapi", LOG_PID, LOG_LOCAL0);
if (!is_string($data))
	$data=json_encode($data);
syslog(LOG_WARNING, "$er {$_SERVER['REMOTE_ADDR']} ({$_SERVER['HTTP_USER_AGENT']})");
if ($data)
	syslog(LOG_WARNING, $data);
if (!is_null($e))
	syslog(LOG_WARNING, sprintf('Exception [%d] [%d in %s]: %s', $e->getCode(), $e->getLine(), $e->getFile(), $e->getMessage()));
closelog();
}

