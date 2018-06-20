<?php

require_once('AES.class.php');

/**
 * Handle user authentication to services
 *
 */
class LoginHandler
{
private $isClientAuth=false;
private $isAuth=false;
//
private $config;
private $appdata;
private $be;

public function __construct(array $config, array $app, BackendActionsInterface &$be)
{
$this->be=$be;
$this->appdata=$app;
$h=getallheaders();

// Check client API key, if it's not ok, then we report that directly and die
if (!$this->checkAuthenticationKey($h)) {
	Flight::json(Response::data(500, 'Client authentication error, invalid client API key', 'login'), 500);
	die(); // Fatal error
}

// Check if we are given a login token, if not then client *user* is not yet logged in, not fatal and default 
if (empty($h['X-Auth-Token'])) {
	slog("X-Auth-Token is empty");
	return;
}

try {
	$this->be->set_auth_token($h['X-Auth-Token']);
	$this->isAuth=$this->be->check_auth();
} catch (Exception $e) {
	Flight::json(Response::data(500, 'Internal authentication failure', 'login', array('error'=>$e->getMessage())), 500);
	die(); // Fatal error, we need to die here as if auth fails, user shouldn't be allowed to do anything
}
}

private function checkAuthenticationKey(array $h)
{
if (empty($h['X-AuthenticationKey']))
	return false;

$r=$this->be->auth_apikey($h['X-AuthenticationKey']);
if ($r) {
	$this->isClientAuth=true;
	return true;
}
$this->isClientAuth=false;
$this->isAuth=false;
return false;
}

/**
 * Is the client (not user) authenticated with client key ?
 */
public function isClientAuthenticated()
{
return $this->isClientAuth;
}

/**
 * Is the user & client authenticated ?
 */
public function isAuthenticated()
{
return $this->isAuth && $this->isClientAuth;
}

/**
 * Check token sent by client, if we have it stored the we are good to go
 *
 */
protected function checkAuthToken()
{
return $this->be->check_auth();
}

/**
 * Login user with username/password supplied in the request
 */
public function login()
{
$r=Flight::request()->data;

if (empty($r['username']) || empty($r['password'])) {
	throw new ResponseException('Login operation missing required parameters', 403);
}

$username=$r['username'];
$password=$r['password'];

try {
	$this->be->set_auth($username, $password);
	$u=$this->be->login();

	// Fill in the current application data, if set
	if (is_array($this->appdata)) {
		$u['app']=array(
		'version'=>$this->appdata['version'],
		'package'=>$this->appdata['apk']
		);
	}

	Flight::json(Response::data(200, 'Login OK', 'login', $u));
} catch (Exception $e) {
	slog("Login failure", 'login', $e);
	Flight::json(Response::data(403, 'Login failure', 'login', array('error'=>$e->getMessage())), 403);
}
}

public function logout()
{
Flight::json(Response::data(200, 'Logout OK', 'logout'));
}

public function userCurrent()
{
Flight::json(Response::data(200, 'User data', 'user', array()));
}

public function user($uid)
{
$u=$this->be->retrieve_user($uid);
Flight::json(Response::data(200, 'User data', 'user', $u));
}

} // class
