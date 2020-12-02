<?php
/**
 * Location endpoint handler
 *
 * @package Handler
 */
class LocationHandler extends Handler
{

public function locations()
{
$this->checkAuth();

try {
	$tmp=$this->be->get_locations();
} catch (Exception $e) {
	Response::json(500, 'Failed to retrieve locations');
	return false;
}

$r=array();
foreach ($tmp as $loc) {
	// Geo location is optional, check if set and if so use it. In GeoJSON format.
	if (property_exists($loc, 'geo')) {
		if (is_string($loc->geo)) {
			$g=json_decode($loc->geo);
		} else {
			$g=$loc->geo;
		}
		if (is_object($g) && property_exists($g, 'coordinates'))
			$loc->geo=$g->coordinates;
		else
			$loc->geo=null;
	} else {
		$loc->geo=null;
	}
	$r[$loc->id]=$loc;
}

Response::json(200, 'Locations', $r);
}

} // class
