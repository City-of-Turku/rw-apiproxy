<?php
/**
 * RW API Proxy
 *
 * Talks a REST API with the clients and handles the requests to Drupal services REST API
 * The clients do not need to know the peculiarities of the Druapl services API.
 * Data can also be cached.
 *
 * All code is released under the GNU General Public License.
 *
 * Uses the Flight micro-framework, http://flightphp.com/ , Flight is released under the MIT license.
 *
 */

// Enable for development only
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
// error_reporting(E_ALL);

if(!function_exists('hash_equals')) {
  function hash_equals($str1, $str2) {
    if(strlen($str1) != strlen($str2)) {
      return false;
    } else {
      $res = $str1 ^ $str2;
      $ret = 0;
      for($i = strlen($res) - 1; $i >= 0; $i--) $ret |= ord($res[$i]);
      return !$ret;
    }
  }
}

require('vendor/autoload.php');
require('lib/drupal/DrupalServiceAPIClient.class.php');
require('lib/Syslog.php');
require('lib/Response.class.php');
require('lib/LoginHandler.class.php');
require('lib/ProductHandler.class.php');
require('lib/OrderHandler.class.php');
require('lib/NewsHandler.class.php');
require('lib/LocationHandler.class.php');
require('lib/ApplicationHandler.class.php');

/*****************************************************************************/

if (!file_exists("config.ini"))
	die('Configuration file config.ini is missing');

$config=parse_ini_file("config.ini", true);
$api=$config['Generic'];

$appdata=$config['MobileApp'];

switch ($appdata['backend']) {
	case 'drupal':
		$be=new DrupalServiceAPIClient($api['drupal_url']);
		$be->set_auth_type(AUTH_SESSION);
		// $be->set_debug(true);
	break;
	case 'prestashop':
		$be=new PrestashopServiceAPIClient($api['prestashop_url']);
	break;
	default:
		die('Backend not set or invalid');
}

$l = new LoginHandler($api, $appdata, $be);
$p = new ProductHandler($l);
$loc = new LocationHandler($l, $be);
$order = new OrderHandler($l, $be);
$app = new ApplicationHandler($l, $appdata);
$news = new NewsHandler($api);

Flight::route('/', function(){
  Flight::json(Response::data(200, 'API Version 3', 'version', array('version'=>3, 'level'=>1)));
});

Flight::route('GET /version', function(){
  Flight::json(Response::data(200, 'API Version 3', 'version', array('version'=>3, 'level'=>1)));
});

// Authentication & current user
Flight::route('POST /auth/login', array($l, 'login'));
Flight::route('POST /auth/logout', array($l, 'logout'));
Flight::route('GET /auth/user', array($l, 'userCurrent'));

// User information
Flight::route('GET /user/@id:[0-9]{1,6}', array($l, 'user'));

// Product related requests
Flight::route('GET /product/barcode/@barcode:[A-Z]{3}[0-9]{6,9}', array($p, 'searchBarcode'));
Flight::route('GET /product/image/@style/@fid:[0-9]{1,5}', array($p, 'getProductImage'));
Flight::route('GET /products/categories', array($p, 'categories'));
Flight::route('GET /product/latest', array($news, 'productsFeed'));
Flight::route('GET /products/search', array($p, 'search'));
Flight::route('GET /products/@page:[0-9]{1,4}', array($p, 'browse'));
Flight::route('GET /products', array($p, 'browse'));

Flight::route('POST /product', array($p, 'add'));
Flight::route('PUT /product', array($p, 'update'));
Flight::route('DELETE /product', array($p, 'delete'));

// App download
Flight::route('GET /download/@apk', array($app, 'download'));

// Orders
Flight::route('GET /orders', array($order, 'orders'));
//Flight::route('POST /orders', array($order, 'browse'));

// Storage locations list endpoint
Flight::route('GET /locations', array($loc, 'locations'));

// RSS News feed endpoint
Flight::route('GET /news', array($news, 'newsFeed'));

Flight::map('notFound', function(){
 Flight::json(array('error' => 404), 404);
});

Flight::map('error', function(Exception $e) {
	$c=$e->getCode();
	if ($c<400)
		$c=500;
	$m=$e->getMessage().' '.$e->getLine();
	Flight::json(Response::data($c, $m, 'error'), 500);
});

Flight::start();
?>
