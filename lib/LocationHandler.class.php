<?php

class LocationHandler
{
private $l;
private $be;

public function __construct(LoginHandler &$l, BackendActionsInterface &$be)
{
$this->l=$l;
$this->be=$be;
}

public function locations()
{
if (!$this->l->isAuthenticated())
        return Flight::json(Response::data(401, 'Client is not authenticated', 'locations'), 401);

$tmp=$this->be->get_locations();
if (!$tmp)
	return Flight::json(Response::data(500, 'Failed to retrieve locations', 'locations'), 500);

$r=array();
foreach ($tmp as $loc) {
	if (property_exists($loc, 'geo')) {
		$g=json_decode($loc->geo);
		if (property_exists($g, 'coordinates'))
			$loc->geo=$g->coordinates;
		else
			$loc->geo=null;
	}
	$r[$loc->id]=$loc;
}

Flight::json(Response::data(200, 'locations' , 'locations', $r));
}

} // class
