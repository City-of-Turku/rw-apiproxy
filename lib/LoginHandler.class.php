<?php
require_once('AES.class.php');

class AuthenticationException extends Exception {}

/**
 * Handle user authentication to services
 *
 */
class LoginHandler Extends Handler
{
private $isClientAuth=false;
private $isAuth=false;
//
private $config;
private $appdata;

public function __construct(array $config, array $app, BackendActionsInterface &$be)
{
$this->be=$be;
$this->appdata=$app;

// Check client API key, if it's not ok, then we report that directly and die
if (!$this->checkAuthenticationKey()) {
	Response::json(500, 'Client authentication error, invalid client API key');
	die(); // Fatal error
}

// Check if we are given a login token, if not then client *user* is not yet logged in, not fatal and default 
// X-Auth-Token
if (empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
	//slog("X-Auth-Token is empty");
	return;
}

try {
	$this->be->set_auth_token($_SERVER['HTTP_X_AUTH_TOKEN']);
	$this->isAuth=$this->be->check_auth();
} catch (Exception $e) {
	Response::json(500, 'Internal authentication failure', array('error'=>$e->getMessage()));
	die(); // Fatal error, we need to die here as if auth fails, user shouldn't be allowed to do anything
}
}

private function checkAuthenticationKey()
{
// X-AuthenticationKey
if (empty($_SERVER['HTTP_X_AUTHENTICATIONKEY']))
	return false;

$r=$this->be->auth_apikey($_SERVER['HTTP_X_AUTHENTICATIONKEY']);
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

	Response::json(200, 'Login OK', $u);
} catch (Exception $e) {
	slog("Login failure", 'login', $e);
	Response::json(403, 'Login failure', array('error'=>$e->getMessage()));
}
}

public function logout()
{
Response::json(200, 'Logout OK');
}

public function userCurrent()
{
Response::json(200, 'User data');
}

public function user($uid)
{
Response::json(200, 'User data', $this->be->retrieve_user($uid));
}

} // class
