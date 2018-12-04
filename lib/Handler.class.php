<?php
/**
 * Request handler base class
 *
 * Common setup for most request handlers.
 * Includes frequently used helper methods.
 */

class Handler
{
protected $l;
protected $be;
protected $c;

public function __construct(LoginHandler &$l, BackendActionsInterface &$be, array $config=null)
{
$this->l=$l;
$this->c=$config;
$this->be=$be;
}

/**
 * checkAuth()
 *
 * Check if user has authenticated and if not throws a AuthenticationException()
 * Simplifies the authentication checks we need to do in most of the request handlers,
 * we can let the Fligh exception handler take care of the final JSON response.
 *
 **/
public function checkAuth()
{
if (!$this->l->isAuthenticated())
	throw new AuthenticationException('Client is not authenticated', 401);
}

}
