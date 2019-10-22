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

// Handlers
require('lib/Response.class.php');
require('lib/Handler.class.php');
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
	case 'drupal7':
	case 'drupal':
		require('lib/drupal7/DrupalServiceAPIClient.class.php');
		require('lib/drupal7/DrupalActions.class.php');

		$bc=$config['Drupal'];
		$be=new DrupalActions($api, $bc);
	break;
	case 'prestashop':
		require('lib/prestashop/PrestashopActions.class.php');

		$bc=$config['Prestashop'];
		$be=new PrestashopActions($api, $bc);
	break;
	default:
		die('Backend not set or invalid');
}

$l = new LoginHandler($api, $appdata, $be);
$p = new ProductHandler($l, $be, $api);
$loc = new LocationHandler($l, $be, $api);
$order = new OrderHandler($l, $be, $api);
$app = new ApplicationHandler($l, $be, $appdata);
$news = new NewsHandler($api);

function versionResponse() {
	Response::json(200, 'API Version 4', 'version', array('version'=>4, 'level'=>1));
	die();
}

Flight::route('GET /', 'versionResponse');
Flight::route('GET /version', 'versionResponse');

// Authentication & current user
Flight::route('POST /auth/login', array($l, 'login'));
Flight::route('POST /auth/logout', array($l, 'logout'));
Flight::route('GET /auth/user', array($l, 'userCurrent'));

// User information
Flight::route('GET /users/@id:[0-9]{1,6}', array($l, 'user'));

// Product related requests
Flight::route('GET /products/barcode/@barcode:[A-Z]{3}[0-9]{6,9}', array($p, 'getProduct'));
Flight::route('GET /products/@page:[0-9]{1,4}', array($p, 'browse'));
Flight::route('GET /products', array($p, 'browse'));

// Product images, path is not under products as the reference is a file identifier, not product
Flight::route('GET /images/@style/@fid:[0-9]{1,5}', array($p, 'getProductImage'));

Flight::route('POST /products', array($p, 'add'));
Flight::route('PUT /products/@barcode:[A-Z]{3}[0-9]{6,9}', array($p, 'update'));
Flight::route('DELETE /products/@barcode:[A-Z]{3}[0-9]{6,9}', array($p, 'delete'));

// App download
Flight::route('GET /download/@apk', array($app, 'download'));

// Orders
Flight::route('GET /orders', array($order, 'orders'));
Flight::route('GET /orders/@status', array($order, 'orders'));
Flight::route('GET /orders/@order', array($order, 'order'));
Flight::route('POST /orders', array($order, 'create'));

Flight::route('POST /orders/@oid:[0-9]{1,5}/status', array($order, 'setStatus'));

Flight::route('GET /cart', array($order, 'cart'));
Flight::route('POST /cart', array($order, 'clearCart')); // XXX Delete ?
Flight::route('POST /cart/item', array($order, 'addProduct'));
Flight::route('POST /cart/checkout', array($order, 'checkout'));

// Metadata endpoints
Flight::route('GET /locations', array($loc, 'locations'));
Flight::route('GET /categories', array($p, 'categories'));
Flight::route('GET /colors', array($p, 'colors'));

// RSS News feed endpoint
Flight::route('GET /news', array($news, 'newsFeed'));

Flight::map('notFound', function() {
	Response::json(404, 'Not found');
	die();
});

Flight::map('error', function($e) {
	if ($e instanceof AuthenticationException) {
  		return Response::json($e->getCode(), $e->getMessage());
	}
	$c=$e->getCode();
	if ($c<400) $c=500;
	slog("Internal error exception", $e->getMessage(), $e, true);
	Response::json($c, "Internal system error");
});

Flight::start();
?>
