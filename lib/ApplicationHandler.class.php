<?php
/**
 * Handler for mobile application apk download requests
 *
 * @package Handler
 */
class ApplicationHandler extends Handler
{

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
$this->checkAuth();
$file='pkgs/'.$this->c['apk'];

if (file_exists($file))
	$this->sendFile($file);

Response::json(404, 'Download not found');
}

} // class
