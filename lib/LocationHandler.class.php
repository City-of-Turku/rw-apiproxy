<?php

class LocationHandler extends Handler
{

public function locations()
{
$this->checkAuth();

try {
	$tmp=$this->be->get_locations();
} catch (Exception $e) {
	return Response::json(500, 'Failed to retrieve locations');
}

$r=array();
foreach ($tmp as $loc) {
	if (property_exists($loc, 'geo')) {
		$g=json_decode($loc->geo);
		if (is_object($g) && property_exists($g, 'coordinates'))
			$loc->geo=$g->coordinates;
		else
			$loc->geo=null;
	}
	$r[$loc->id]=$loc;
}

Response::json(200, 'Locations', $r);
}

} // class
