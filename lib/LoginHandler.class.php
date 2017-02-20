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
private $token;
private $key='7e2bd40c210d25c738318f706';
private $hmac_key='';
//
private $uid;
private $session_id;
private $session_name;
private $session_token;
//
private $config;
private $appdata;
private $drupal;
//
private $aes;

public function __construct(array $config, array $app, &$drupal)
{
$this->drupal=$drupal;
$this->appdata=$app;
$h=getallheaders();

$this->aes=new AES($this->key, $this->hmac_key);

if (!empty($h['X-AuthenticationKey'])) {
	// XXX: Check against authorized client keys
	$this->isClientAuth=true;
} else {
	$this->isClientAuth=false;
	$this->isAuth=false;
	return;
}

if (!empty($h['X-Auth-Token'])) {
	$this->token=$h['X-Auth-Token'];
	$this->isAuth=$this->checkAuthToken();
}
}

/**
 * Is the client (not user) authenticated with client key ?
 */
public function isClientAuthenticated()
{
return $this->isClientAuth;
}

/**
 * Is the user authenticated ?
 */
public function isAuthenticated()
{
return $this->isAuth;
}

public function getSession()
{
}

/**
 * Client send us the encrypted session cookie data, auth+decrypt it and set the session data.
 * Returns: true if ok, false if data is wrong
 */
private function setAuthdata($data)
{
global $drupal;

if (!is_string($data) || $data===false)
	return false;

$s=explode(':', $data);
$this->session_id=$s[0];
$this->session_name=$s[1];
$this->session_token=$s[2];
$this->uid=(int)$s[3];

if ($this->uid<1)
	return false;

$drupal->set_session_data($this->session_id, $this->session_name, $this->session_token, $this->uid);

return true;
}

/**
 * Check token sent by client, if we have it stored the we are good to go
 *
 */
protected function checkAuthToken()
{
$tmp=$this->aes->decrypt($this->token);

if ($tmp===false)
	return false;

return $this->setAuthdata($tmp);
}

/**
 * Login user with username/password supplied in the request
 */
public function login()
{
global $drupal;

$r=Flight::request()->data;

if (empty($r['username']) || empty($r['password'])) {
	throw new ResponseException('Login operation missing required parameters', 400);
}

// XXX: Authenticate with drupal
$username=$r['username'];
$password=$r['password'];

try {
	$drupal->set_auth($username, $password);
	$ur=$drupal->login();
	$u=array();
	// Construct a login key from session data
	$t=sprintf('%s:%s:%s:%d', $ur->sessid, $ur->session_name, $ur->token, $ur->user->uid);

	$u['apitoken']=$this->aes->encrypt($t);
	$u['username']=$ur->user->name;
	$u['uid']=$ur->user->uid;
	$u['created']=$ur->user->created;
	$u['access']=$ur->user->access;
	$u['email']=$ur->user->mail;
	$u['roles']=$ur->user->roles;
	if (property_exists($ur->user, "field_name")) {
		// XXX
	}
	if (property_exists($ur->user, "field_image")) {
		// XXX
	}

	$u['app']=array(
		'version'=>$this->appdata['version'],
		'package'=>$this->appdata['apk']
		);

	Flight::json(Response::data(200, 'Login OK', 'login', $u));
} catch (Exception $e) {
	Flight::json(Response::data(403, 'Login failure', 'login', array('error'=>$e->getMessage())), 403);
	return false;
}

return true;
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
global $drupal;

$u=$drupal->retrieve_user($uid);
Flight::json(Response::data(200, 'User data', 'user', $u));
}

} // class
