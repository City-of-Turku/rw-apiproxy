<?php

function slog($er, $data='')
{
openlog("rvapi", LOG_PID, LOG_LOCAL0);
if (!is_string($data))
	$data=json_encode($data);
syslog(LOG_WARNING, "$er {$_SERVER['REMOTE_ADDR']} ({$_SERVER['HTTP_USER_AGENT']})");
syslog(LOG_DEBUG, $data);
closelog();
}

