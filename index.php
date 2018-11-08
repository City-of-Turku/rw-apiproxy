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

date_default_timezone_set('Europe/Helsinki');

// Enable for development only
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require('vendor/autoload.php');
require_once('lib/AES.class.php');
require('lib/Syslog.php');
require('lib/Product.class.php');
require('lib/Request.class.php');
require('lib/BackendActionsInterface.class.php');

// Drupal backend API
require('lib/drupal/DrupalServiceAPIClient.class.php');
require('lib/drupal/DrupalActions.class.php');

// Prestashop backend API
require('lib/prestashop/PrestashopActions.class.php');

// Handlers
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

switch ($api['backend']) {
	case 'drupal':
		$bc=$config['Drupal'];
		$be=new DrupalActions($api, $bc);
	break;
	case 'prestashop':
		$bc=$config['Prestashop'];
		$be=new PrestashopActions($api, $bc);
	break;
	default:
		die('Backend not set or invalid');
}

$l = new LoginHandler($api, $appdata, $be);
$p = new ProductHandler($l, $be);
$loc = new LocationHandler($l, $be);
$order = new OrderHandler($l, $be);
$app = new ApplicationHandler($l, $appdata);
$news = new NewsHandler($api);

function versionResponse() {
 Flight::json(Response::data(200, 'API Version 3', 'version', array('version'=>3, 'level'=>1)));
 die();
}

Flight::route('GET /', 'versionResponse');
Flight::route('GET /version', 'versionResponse');

// Authentication & current user
Flight::route('POST /auth/login', array($l, 'login'));
Flight::route('POST /auth/logout', array($l, 'logout'));
Flight::route('GET /auth/user', array($l, 'userCurrent'));

// User information
Flight::route('GET /user/@id:[0-9]{1,6}', array($l, 'user'));

// Product related requests
Flight::route('GET /product/barcode/@barcode:[A-Z]{3}[0-9]{6,9}', array($p, 'getProduct'));
Flight::route('GET /product/image/@style/@fid:[0-9]{1,5}', array($p, 'getProductImage'));
Flight::route('GET /product/latest', array($news, 'productsFeed'));
Flight::route('GET /products/search', array($p, 'search'));
Flight::route('GET /products/@page:[0-9]{1,4}', array($p, 'browse'));
Flight::route('GET /products', array($p, 'browse'));

Flight::route('GET /categories', array($p, 'categories'));

Flight::route('POST /product', array($p, 'add'));
Flight::route('PUT /product', array($p, 'update'));
Flight::route('DELETE /product', array($p, 'delete'));

// App download
Flight::route('GET /download/@apk', array($app, 'download'));

// Orders
Flight::route('GET /orders', array($order, 'orders'));
//Flight::route('GET /orders/@status', array($order, 'orders'));
Flight::route('GET /order/@order', array($order, 'order'));
Flight::route('POST /orders', array($order, 'create'));

// Storage locations list endpoint
Flight::route('GET /locations', array($loc, 'locations'));

// RSS News feed endpoint
Flight::route('GET /news', array($news, 'newsFeed'));

Flight::map('notFound', function(){
  Flight::json(Response::data(404, 'Not found', 'error'), 404);
});

Flight::map('error', function($e) {
  $c=$e->getCode();
  if ($c<400)
    $c=500;
  slog("Internal error", $e->getMessage(), $e);
  Flight::json(Response::data($c, "Internal system error", 'error'), 500);
});

Flight::start();
?>
