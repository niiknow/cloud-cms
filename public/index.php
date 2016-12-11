<?php
/****************************************************************
 * Init
 ****************************************************************/
/**
 * constants
 */
define('TIME_BEGIN', microtime(true));

// Define path to application directory
defined('APP_PATH')
|| define('APP_PATH', realpath(dirname(__FILE__) . '/../'));
$classLoader = require_once APP_PATH . '/vendor/autoload.php';

/**
 * Load config
 */
$config = [
    "tenantCode" => "394",
    "cloudQuery" => "http://clientapi.gsn2.com/api/v1/Content/GetPartial/394/0?name=[pageName]",
    "ttl"        => 600,
];

// Setup File Path on your config files
\phpFastCache\CacheManager::setDefaultConfig(array(
    "path" => APP_PATH . '/cache/data',
));

// In your class, function, you can call the Cache
$cacheInstance = \phpFastCache\CacheManager::getInstance('files');

// start session just in case user want to store something
session_start();

/****************************************************************
 * Bootstrap
 ****************************************************************/

function cache($key, $stringData = null, $ttl = 0)
{
    $cacheInstance = \phpFastCache\CacheManager::getInstance('files');
    $cacheItem     = $cacheInstance->getItem($key);
    $existingData  = $cacheItem->get();
    if (is_null($existingData)) {
        if ($ttl > 1) {
            $cacheItem->set($stringData)->expiresAfter($ttl); //in seconds, also accepts Datetime
            $cacheInstance->save($cacheItem); // Save the cache item just like you do with doctrine and entities
        }
    }
    return $cacheItem->get();
}

/**
 * get current page url
 * @return string current page url
 */
function currentPageUrl()
{
    $pageURL = 'http';

    if ($_SERVER["SERVER_PORT"] == "443") {$pageURL .= "s";}

    $pageURL .= "://";

    if ($_SERVER["SERVER_PORT"] != "80" || $_SERVER["SERVER_PORT"] != "443") {
        $pageURL .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
    } else {
        $pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
    }

    return $pageURL;
}

function getCurrentPageNames()
{
    $pageName = ["Home Page"];
    $path     = trim(parse_url(currentPageUrl(), PHP_URL_PATH), "/");

    if (!empty($path)) {
        return explode("/", $path);
    }

    return $pageName;
}

function validatePageNames($pageNames, $util)
{
    if (count($pageNames) > 5) {
        return false;
    }

    // add any other logic here such as your custom pages
    // example healthcheck
    if ($pageNames[0] == "healthcheck.txt") {
        echo 'OK';
        header("Content-Type: text/plain");
        return false;
    }

    return true;
}

/****************************************************************
 * Functions
 ****************************************************************/
function d($msg)
{
    echo '<pre>';
    print_r($msg);
    echo '</pre>';
}

class MyUtil
{
    /**
     * make http request
     * @param  string $url          the url
     * @param  string $method       method GET, POST, etc..
     * @param  object $headers      headers array or string
     * @param  object $content      content body: array or string
     * @param  object &$respheaders response headers
     * @return string response body
     */
    public function httpRequest($url, $method = 'GET', $headers = ["Content-Type: application/json"], $content = null, &$respheaders = null)
    {
        if (empty($method)) {
            $method = 'GET';
        }
        // var_dump($url);

        /**
         * Make post query
         */
        if (!empty($content) && is_array($content)) {
            $content = http_build_query($content);
        }

        $curl = curl_init();

        /**
         * Set headers
         */
        if (!empty($headers)) {
            if (!is_array($headers)) {
                $headers = explode("\n", $headers);
            }

            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        if (!is_null($content)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
        }

        //curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_VERBOSE, 1);
        curl_setopt($curl, CURLOPT_HEADER, 1);

        $data = curl_exec($curl);

        /**
         * get headers and body
         */
        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $respheaders = substr($data, 0, $header_size);
        $body        = substr($data, $header_size);
        // var_dump($data);
        curl_close($curl);

        return $body;
    }

    /**
     * get request with cache
     * @param  string  $url   the url
     * @param  integer $ttl   the cache duration
     * @return string  data
     */
    public function getRequestWithCache($url, $ttl = 120)
    {
        $key          = hash('md5', $url);
        $existingData = cache($key);
        if (!is_null($existingData)) {
            return $existingData;
        }

        $existingData = $this->httpRequest($url);
        return cache($key, $existingData, $ttl);
        // return $existingData;
    }

    /**
     * proxy request
     * @param  string $url
     * @return string response body
     */
    public function httpProxyQuery($url)
    {
        $respheaders = null;

        $data = httpRequest($url, $_SERVER['REQUEST_METHOD'], getallheaders(), $_POST, $respheaders);

        /**
         * Send response headers to browser
         */
        $this->sendHeaders($respheaders, true);

        return $data;
    }

    /**
     * apply multiple headers
     * @param object  $headers  array of headers or string
     * @param boolean $replace  true to replace existing header
     * @param lambda  $matchers apply matching anonymous function
     */
    public function sendHeaders($headers, $replace = true, $matchers = null)
    {
        static $ignores = array('Transfer-Encoding', 'Location');

        if (!is_array($headers)) {
            $headers = explode("\n", $headers);
        }

        foreach ($headers as $h) {
            if (empty($h)) {
                continue;
            }

            if (is_callable($matchers) && !$matchers($h)) {
                continue;
            }

            $parts = explode(':', $h, 2);

            if (count($parts) >= 2) {
                list($name, $value) = $parts;
                if (in_array($name, $ignores)) {
                    continue;
                }

                if ($name == 'Set-Cookie') {
                    $cookiepart = explode(';', $value);
                    $cookiepart = explode('=', $cookiepart[0]);

                    setcookie(trim($cookiepart[0]), trim($cookiepart[1]), time() + 60 * 60 * 24 * 30, '/');
                    continue;
                }
            }

            header($h, $replace);
        }
    }

    /**
     * check if remote url exists
     * @param  string    $url
     * @return boolean
     */
    public function remoteUrlExists($url)
    {
        $headers = @get_headers($url);
        $http    = explode(' ', $headers[0]);

        if (count($http) < 2) {
            return false;
        }

        $http = $http[1];
        return $http != 404 && $http < 500;
    }

    /**
     * send redirect/location header
     * @param string $url
     */
    public function sendRedirect($url)
    {
        header("Location: " . $url);
        exit;
    }

    /**
     * cause page to refresh
     */
    public function sendRefresh()
    {
        location(strtok($_SERVER["REQUEST_URI"], "?"));
    }

    public function startsWith($haystack, $needle)
    {
        $length = strlen($needle);
        return (substr($haystack, 0, $length) === $needle);
    }

    public function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }

        return (substr($haystack, -$length) === $needle);
    }
}

/****************************************************************
 * View Engine and Rendering
 ****************************************************************/
$util   = new MyUtil();
$loader = new \Twig_Loader_String();
$twig   = new \Twig_Environment($loader, array(
    'cache' => APP_PATH . '/cache/twig',
    'debug' => false,
));

$pageNames = getCurrentPageNames();
if (!validatePageNames($pageNames, $util)) {
    // return 404
    $pageNames = ["404"];
}

$pageName   = join("-", $pageNames);
$cloudQuery = $config["cloudQuery"];
$dataUrl    = str_replace("[pageName]", urlencode($pageName), $cloudQuery);
$pageData   = $util->getRequestWithCache($dataUrl, $config["ttl"]);
$pageModel  = $pageData;

if (isset($pageData) && !empty($pageData) && preg_match("/[\{\]]+/i", $pageData)) {
    $pageModel = json_decode($pageData);
} else {
    if ($pageName == "404") {
        http_response_code(500);
        echo "Error 500: issue connecting to the database backend and/or not having a custom 404 page.";
        return;
    }

    $util->sendRedirect("/404");
    return;
}

/**
 * Customs
 */
$twig->addExtension(new \Twig_Extension_StringLoader());
$twig->addFunction(new \Twig_SimpleFunction('debug', 'd'));
$twig->addFunction(new \Twig_SimpleFunction('is_object', 'is_object'));
$twig->addFunction(new \Twig_SimpleFunction('microtime', 'microtime'));
$twig->addFunction(new \Twig_SimpleFunction('json_decode', 'json_decode'));
$twig->addFunction(new \Twig_SimpleFunction('json_encode', 'json_encode'));
$twig->addGlobal('app', [
    'get'     => $_GET,
    'post'    => $_POST,
    'request' => $_REQUEST,
    'session' => @$_SESSION ?: [],
    'server'  => $_SERVER,
]);

$template = file_get_contents(APP_PATH . '/public/default.twig');
if (!empty($pageModel->tpl)) {
    $template = $pageModel['tpl'];
}

/**
 * Display
 */
echo $twig->render($template, [
    'pg'      => $pageModel,
    'config'  => $config,
    'ut'      => $util,
    'dataUrl' => $dataUrl,
]);
