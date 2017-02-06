<?php

class ApplicationHandler
{
private $l;
private $apk;
private $app;

public function __construct(LoginHandler &$l, array $appconf)
{
$this->l=$l;
$this->app=$appconf;
$this->apk='pkgs/'.$this->app['apk'];
}

private function sendFile($file)
{
$quoted=sprintf('"%s"', addcslashes(basename($file), '"\\'));
$size=filesize($file);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename=' . $quoted);
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . $size);

readfile($file);
die();
}

public Function download()
{
if (!$this->l->isAuthenticated())
	return Flight::json(Response::data(401, 'Client is not authenticated', 'download'));

if (file_exists($this->apk))
	$this->sendFile($this->apk);

Flight::json(Response::data(404, 'Download not found', 'download'), 404);
}

} // class
