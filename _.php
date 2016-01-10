<?php
namespace {
    class _ {
        private static $config = array(
            'controller_dir' => './controller',
            'templates_dir' => './templates',
        );
        private function __construct() { }

        public static function init($config) {
            foreach ($config as $k => $v) {
                if (isset(self::$config[$k])) {
                    self::$config[$k] = $v;
                }
            }
        }

        public static function getConfig($key) {
            return isset(self::$config[$key]) ? self::$config[$key] : false;
        }

        public static function __callStatic($name, $args) {
            if (get_parent_class('_'.$name) == '_Singleton') {
                return forward_static_call_array(array('_'.$name, 'getInstance'), $args);
            } else {
                $reflection_class = new ReflectionClass('_'.$name);
                return $reflection_class->newInstanceArgs($args);
            }
        }
        public static function autoload($class) {
            $class = lower(str_replace('\\', '/', $class));
            if (is_readable($file = './' . $class . '.php')) {
                require $file;
            }
        }
    }
    abstract class _Singleton {
        final protected function __construct() { }
        final private function __clone() { }
        final public static function getInstance() {
            if (!isset(static::$_instance)) {
                static::$_instance = new static;
                call_user_func_array(array(static::$_instance, 'init'), func_get_args());
            }
            return static::$_instance;
        }
        protected function init() { }
    }
    class _Router extends _Singleton {
        protected static $_instance;

        private $options;
        private $routes;

        protected function init($routes = array(), $options = array()) {
            $this->options = (object)array_merge(array('ext' => ''), $options);
            $this->routes = $routes;
        }
        public function route() {
            $controller = $action = 'index';
            $params = array();

            // strip path
            //$dir_ws = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
			$dir_ws = _::Request()->relpath;
            $uri = substr($_SERVER['REQUEST_URI'], strlen($dir_ws));

            // strip QS
            $uri = strtok(trim($uri, '/'), '?');

            // strip ext
            if (($ext = $this->options->ext) && ss($uri, len($ext)*-1) == $ext) {
                $uri = ss($uri, 0, len($ext)*-1);
            }

            $uri_parts = explode('/', $uri);

            // Custom Route
            $isRegexRoute = $isPlaceholderRoute = false;
            foreach ($this->routes as $k => $v) {

                if (!$k) {
                    list($controller, $action) = $v;
                } else if ($k == $uri_parts[0] ||
                    ($k[0] == '#' && $isRegexRoute = preg_match($k, $uri, $matches)) ||
                    ($isPlaceholderRoute = strpos($k, ':')) !== false) {

                    // regex route
                    if ($isRegexRoute !== false) {
                        foreach ((array)$matches as $i => $match) {
                            if ($i == 0) continue;
                            foreach ($v[2] as $param_key => &$param_value) {
                                $param_value = str_replace("\$$i", $match, $param_value);
                            }
                        }
                        $uri_parts = array();
                    }
                    // placeholder route
                    else if ($isPlaceholderRoute !== false) {
                        $route_parts = explode('/', trim($k, '/'));
                        $hit = false;
                        foreach ($uri_parts as $index => $uri_part) {
                            $route_part = ifset($route_parts[$index]);
                            #echo "$route_part $uri_part<br />";
                            if ($route_part[0] != ':' && $route_part != $uri_part) {
                                continue 2;
                            } else if ($route_part[0] == ':') {
                                foreach ($v[2] as $param_key => &$param_value) {
                                    if ($param_value == $route_part) {
                                        $param_value = $uri_part;
                                        $hit = true;
                                    }
                                }
                            }
                        }
                        if (!$hit) continue;
                        $uri_parts = array();
                    } else if (count($uri_parts) > 1) {
                        $uri_parts = array_combine(range(3,count($uri_parts)+1), array_slice($uri_parts, 1));
                    } else {
                        $uri_parts = array();
                    }
                    if (isset($v[0])) $controller = $v[0];
                    if (isset($v[1])) $action = $v[1];
                    if (isset($v[2])) $params = $v[2];

                    _::Request()->setEnv($controller, $action, $params);
                    return $this;
                }
            }
            // Normal Route
            foreach($uri_parts as $k => $part) {
                if (!$part) { continue; }
                if ($k === 0) {
                    $controller = $part;
                } else if ($k === 1) {
                    $action = $part;
                } else {
                    // Parameter
                    if (isset($key)) {
                        $params[$key] = $part;
                        unset($key);
                    } else {
                        $key = $part;
                    }
                }
            }
            if (isset($key)) {
                $params[] = $part;
            }

            _::Request()->setEnv($controller, $action, $params);
            return $this;
        }
        public function getExt() {
            return $this->options->ext;
        }
    }
    class _Request extends _Singleton {
        protected static $_instance;
        private $__controller = 'index';
        private $__action = 'index';
        private $__params = array();

        public function init($relpath = false, $uri = '', $host = '', $protocol = 'http://') {
            $this->relpath = $relpath ? $relpath : rtrim(dirname($_SERVER['SCRIPT_NAME']), './');
	        $this->uri = ifset($_SERVER['REQUEST_URI'], $uri);
            $this->host = ifset($_SERVER['HTTP_HOST'], $host);
            $this->protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] ? 'https://' : $protocol;
        }
        public function getController() {
            return $this->__controller;
        }
        public function getAction() {
            return $this->__action;
        }
        public function getParam($key, $default = null) {
            return ifset($this->__params[$key], $default);
        }
        public function getParams() {
            return $this->__params;
        }
        public function setEnv($controller, $action, $params) {
            $this->__controller = $controller;
            $this->__action = $action;
            $this->__params = $params;
        }
        public function isPost() {
            return $_SERVER['REQUEST_METHOD'] == 'POST';
        }
        public function getClientIP() {

        }
        public function getFullUri($uri) {
            if (ss($uri, 0, 4) == 'http') return $uri;
            $ext = $uri[strlen($uri)-1] != '/' && strpos($uri, '.') === false ? _::Router()->getExt() : '';
            return rtrim($this->protocol . $this->host . $this->relpath . '/' . $uri . $ext, '/');
        }
        public function __get($key) {
            return ifset($_REQUEST[$key], null);
        }
    }
    class _Response extends _Singleton {
        protected static $_instance;
        private $is_cachable = true;
        public function redirect($uri, $code = 302) {
            header('Location: '. _::Request()->getFullUri($uri), true, $code);
            die();
        }
        public function setStatusCode($msg, $code) {
            header($msg, true, $code);
        }
        public function setCachable($cachable) {
            $this->is_cachaable = (bool)$cachable;
        }
        // todo: CSS & JS Loader? __SCRIPT
    }
    class _Session extends _Singleton {
        protected static $_instance;
        protected $started = false;
        public function init($options = array()) {
            // things like session save handler goes here
            if (isset($_COOKIE[session_name()]) || isset($_GET[session_name()])) {
                $this->start();
            }
        }
        public function start() {
            if (!$this->started) {
                session_start();
                $this->started = true;
            }
        }
        public function stop() {
            $_SESSION = array();
            session_destroy();
            $this->started = false;
        }
        public function started() {
            return $this->started;
        }
        public function setFlash($msg, $key = false) {
            $this->start();
            if ($key) {
                $_SESSION['flash'][$key] = $msg;
            } else {
                $_SESSION['flash'][] = $msg;
            }
        }
        public function getFlash() {
            $this->start();
            if (isset($_SESSION['flash']) && $_SESSION['flash']) {
                $flash = $_SESSION['flash'];
                unset($_SESSION['flash']);
                return $flash;
            }
            return false;
        }
    }
    class _Controller extends _Singleton {
        protected static $_instance;
        protected $view;
        protected function init($dir = null) {
            $this->dir = $dir ?: _::getConfig('controller_dir');
        }
        public $slots = array();

        public function setView(_View $view) {
            $this->view = $view;
        }

        public function go() {
            $controller = _::Request()->getController();
            $R = _::Request();
            $T = &$this;

            _::Hook()->run('controller', 'init', 'pre');
            if (!file_exists($file = $this->dir . '/' . $controller . '.php')) {
                $this->do404();
                die('Controller not found: ' . $controller);
            }
            _::Hook()->run('controller', 'init', 'post');

            if ($this->view && $this->view->hasLayout() && file_exists($layoutController = $this->dir . '/layout.php')) {
                $this->view->context = 'layout';
                include $layoutController;
            }

            if ($this->view) $this->view->context = 'content';
            $return = include $file;

            if ($return == _View::SUCCESS) {
                $this->view->setControllerTemplate($controller.'_'._::Request()->getAction().'_success');
            } else if ($return == _View::ACTION) {

                $this->view->setControllerTemplate($controller.'_'._::Request()->getAction());
            } else if ($return == _View::CONTROLLER) {
                $this->view->setControllerTemplate($controller);
            }
            $this->handleSlots();


            $this->view->render();
        }
        public function handleSlots($view = false) {
            if (!$view) $view = $this->view;
            foreach ($this->slots as $ph => $slot) {
                $view->context = $ph;
                if (!$view->isCached()) {
                    include './' . $slot['slot'];
                }
            }
        }
        public function delegateAction() {
            if (!file_exists($file = $this->dir . '/' . _::Request()->getController() . '_' . _::Request()->getAction() . '.php')) {
                $this->do404();
                die('Controller not found. ' . _::Request()->getController() . ' ' . _::Request()->getAction());
            }

            return $file;
        }
        public function forward($controller = 'index', $action = 'index', $params = array()) {
            _::Request()->setEnv($controller, $action, $params);
            $this->go();
        }
        public function redirect($uri) {
            _::Response()->redirect($uri);
        }
        public function do404() {
            echo 'HTTP/1.1 404 Not Found';
            _::Response()->setStatusCode("HTTP/1.1 404 Not Found", 404);
            die();
        }
        public function do403() {
            echo 'HTTP/1.1 Forbidden ';
            _::Response()->setStatusCode("HTTP/1.1 403 Forbidden", 403);
            die();
        }
        public function registerSlot($placeholder, $slot, $template) {
            $this->slots[$placeholder] = array('slot' => $slot, 'template' => $template);
        }

    }
    class _View {
        const SUCCESS = 2;
        const ACTION = 3;
        const CONTROLLER = 4;

        private $data = array();
        private $layout = false;
        private $controllerTemplate = false;
        private $caches = array();
        public $context = 'layout';

        private $tplDir;

        public function __construct() {
            $this->tplDir = _::getConfig('templates_dir');
        }

        protected function init() {

        }
        public function setDir($dir) {
            $this->tplDir = $dir;
        }
        public function setLayout($layout = 'layout') {
            $this->layout = $layout;
        }
        public function hasLayout() {
            return $this->layout !== false;
        }
        public function render($slot = false) {
            $R = _::Request();
            $T = &$this;

            if ($this->layout) {
                $this->context = 'layout';
                $file = $this->tplDir . '/' . $this->layout . '.phtml';
                $this->layout = false;
            } else if ($slot) {

                if ($slot[0] == '_') {
                    // simple include slot (template only), no context
                    $this->context = 'content';
                    $file = _::getConfig('templates_dir') . '/' . $slot . '.phtml';
                } else {
                    // slot w/ logic, new context
                    $this->context = $slot;
                    $slot = _::Controller()->slots[$slot];
                    $file =  './' . $slot['template'];
                }
            } else {
                $this->context = 'content';
                $controllerTemplate = $this->controllerTemplate ?: _::Request()->getController();
                $file =  $this->tplDir . '/' . $controllerTemplate . '.phtml';
            }

            if (($out = $this->getCache()) === false) {
                if (isset($this->data[$this->context])) {
                    extract($this->data[$this->context], EXTR_REFS);
                }
                if (isset($this->caches[$this->context])) {
                    $cache = $this->caches[$this->context];
                    $cache['cache']->start($cache['cache_id'], $cache['ttl']);
                }

                include $file;

                if (isset($this->caches[$this->context])) {
                    $this->caches[$this->context]['cache']->stop();
                }
            } else {
                echo $out;
            }
        }

        public function __set($key, $var) {
            $this->data[$this->context][$key] = $var;
        }
        public function setAll(array $array) {
            foreach ($array as $k => $v) {
                $this->data[$this->context][$k] = $v;
            }
        }
        public function __get($key) {
            return ifset($this->data[$this->context][$key]);
        }
        public function get($key, $context = null) {
            if (!$context) {
                $context = $this->context;
            }
            return ifset($this->data[$context][$key]);
        }
        public function getAll() {
            return $this->data[$this->context];
        }
        private function remove($var, $context) {
            if (!$context) {
                $context = $this->context;
            }
            unset($this->data[$key][$context]);
        }
        public function enableCache(_Cache $cache, $cache_id, $ttl = '5M') {
            $this->caches[$this->context] = array('cache' => $cache, 'cache_id' => $cache_id, 'ttl' => $ttl);
        }
        public function getCache() {
            if (!isset($this->caches[$this->context])) {
                return false;
            }
            $cache = $this->caches[$this->context];
            return $cache['cache']->get($cache['cache_id']);
        }
        public function isCached() {
            if (!isset($this->caches[$this->context])) {
                return false;
            }
            $cache = $this->caches[$this->context];
            return $cache['cache']->has($cache['cache_id']);
        }
        public function setControllerTemplate($template) {
            $this->controllerTemplate = $template;
        }
    }
    interface CacheBackend {
        public function get($cache_id);
        public function set($cache_id, $value, $ttl, $compress);
        public function delete($cache_id);
        public function has($cache_id);
    }
    class CacheBackendFile implements CacheBackend {
        private $config;
        private $dir;

        public function __construct($dir, $config = array()) {
            $this->config = (object)array_merge(array('gc_prob' => 10), $config);

            $this->dir = $dir . '/';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            if (rand(0, 100) <= $this->config->gc_prob) {
                $this->gc();
            }
        }
        public function get($cache_id) {
            $file = $this->dir . $cache_id;
            if (file_exists($file)) {
                if (filemtime($file) > time()) {
                    $contents =  file_get_contents($file);
                    #$contents = gzuncompress($contents);
                    return unserialize($contents);
                } else {
                    unlink($file);
                }
            }
            return false;
        }
        public function set($cache_id, $value, $ttl, $compress = false) {
            $file = $this->dir . $cache_id;
            $value = serialize($value);
            if ($compress) {
                $value = gzcompress($value);
            }
            file_put_contents($file, $value);
            touch($file, time()+$ttl);
        }
        public function delete($cache_id) {
            $file = $this->dir . $cache_id;

            if (substr($cache_id, -1) == '*') {
                $cnt = 0;
                $files = glob($file);
                foreach ($files as $file) {
                    if (unlink($file)) {
                        $cnt++;
                    }
                }
                return $cnt;
            }
            return file_exists($file) && unlink($file);
        }
        public function has($cache_id) {
            $file = $this->dir . $cache_id;
            return file_exists($file);
        }
        public function gc() {
            $handle = opendir($this->dir);
            while (($file = readdir($handle)) !== false && $file != '.' && $file != '..') {
                if (filemtime($this->dir.$file) < time()) {
                    unlink($this->dir.$file);
                }
            }
        }
    }
	if (class_exists('Memcache')) {
    	class CacheBackendMemcache extends Memcache implements CacheBackend {
	        //@todo: delete /w wildcard
	        private $connected = false;
	        private $host;
	        private $port;
	        private $config;
	        private $cacheIDs;

	        public function __construct($host, $port, $config = array()) {
	            $this->config = (object)array_merge(array('env' => 'default'), $config);
	            $this->host = $host;
	            $this->port = $port;
	        }

	        public function connect() {
	            if ($this->connected) return true;
	            $this->connected = true;
	            parent::connect($this->host, $this->port);
	        }

	        public function set($cache_id, $value, $ttl, $compress) {
	            $this->connect();
	            $compress = $compress ? MEMCACHE_COMPRESSED : 0;
	            parent::set($cache_id, $value, $compress, $ttl);
	        }
	        public function get($cache_id, $flags = 0) {
	            $this->connect();
	            return parent::get($cache_id, $flags);
	        }
	        public function has($cache_id) {
	            $this->connect();
	            if ($this->add($cache_id, '')) {
	                $this->delete($cache_id, 0);
	                return false;
	            }
	            return true;
	        }
	        public function delete($cache_id) {
	            if (substr($cache_id, -1) == '*') {
	                $cnt = 0;
	                $cache_id = substr($cache_id, 0, -1);
	                $this->getCacheIDs();
	                foreach ($this->cacheIDs as $cid) {
	                    if (strpos($cid, $cache_id) === 0) {
	                        if (parent::delete($cid, 0)) {
	                            $cnt++;
	                        }
	                    }
	                }
	                return $cnt;
	            }
	            return parent::delete($cache_id, 0);
	        }
	        public function __wakeup() {
	            $this->connected = false;
	            $this->connect();
	        }
	        private function getCacheIDs() {
	            if ($this->cacheIDs) return;
	            $allSlabs = $this->getExtendedStats('slabs');
	            foreach ($allSlabs as $server => $slabs)
	                foreach ($slabs as $slabId => $slabMeta)
	                    if ((int)$slabId)
	                        foreach ($this->getExtendedStats('cachedump',(int)$slabId) as $server => $entries)
	                            if ($entries)
	                                foreach($entries as $eName => $eData)
	                                    $this->cacheIDs[] = $eName;
	            return $list;
	        }

	    }
	}
    class _Cache {
        private $backend;

        public function __construct(CacheBackend $backend) {
            $this->backend = $backend;
        }
        public function has($cache_id) {
            return $this->backend->has($cache_id);
        }
        public function get($cache_id) {
            return $this->backend->get($cache_id);
        }
        public function set($cache_id, $value, $ttl = '5M', $compress = false) {
            if (!is_int($ttl)) {
                $unit = strtolower(substr($ttl, -1));
                $val = substr($ttl, 0, -1);
                if ($unit == 'm') $ttl = $val * 60;
                else if ($unit == 'h') $ttl = $val * 60 * 60;
                else if ($unit == 'd') $ttl = $val * 60 * 60 * 24;
                else if ($unit == 'w') $ttl = $val * 60 * 60 * 24 * 7;
            }
            $this->backend->set($cache_id, $value, (int)$ttl, $compress);
        }
        public function delete($cache_id) {
            return $this->backend->delete($cache_id);
        }
        public function start($cache_id, $ttl = '5M', $options = array()) {
            $options = (object)array_merge(array('override' => false, 'append' => false), $options);

            $this->backend->connect();
            if ($options->override || ($content = $this->backend->get($cache_id)) === false) {
                $cache = &$this;
                ob_start(function($buffer) use ($cache_id, $ttl, $cache, $options) {
                    $cache->set($cache_id, $buffer, $ttl);
                    if ($options->append) {
                        $buffer .= $options->append;
                    }
                    return $buffer;
                });
                return true;
            } else {
                echo $content;
                return false;
            }

        }
        public function stop() {
            ob_end_flush();
        }
    }
    class _Log {
        const PROD = 1;
        const DEV = 2;
        const DBG = 3;

        private $config = array();

        public function __construct($options = array()) {
            $this->config = (object)array_merge(array('level' => 1, 'type' => 'file', 'dir' => __DIR__.'/tmp/logs', 'name' => 'general.log'), $options);
        }

        public function log($msg, $lvl = _Log::PROD) {
            if ($this->config->level <= $lvl) {
                if ($this->config->type == 'file') {
                    error_log(date('Y-m-d H:i:s') . ': ' . $msg . "\n", 3, $this->config->dir .'/'.$this->config->name);
                } else if ($msg) {
                    echo $msg;
					if ($msg[strlen($msg)-1] != "\r") echo "\n";
                    if ($this->config->type == 'browser') {
                        echo "<br />";
                        ob_flush();
                        flush();
                        echo str_repeat(' ', 1024*64);
                    }
                } else {
					echo "no message";
				}
            }
        }
    }
    class _Event extends _Singleton {

    }
    class _Mailer {

    }
    interface AuthBackend {
        public function auth($userID, $credential);
    }
    class AuthBackendArray implements AuthBackend {
        public function __construct($users) {
            $this->users = $users;
        }
        public function auth($userID, $credential) {
            if (isset($this->users[$userID]) && $this->users[$userID]['password'] == $credential) {
                return array('user_id' => $userID, 'role' => $this->users[$userID]['role']);
            }
            return false;
        }
    }
    class AuthBackendDB implements AuthBackend {
        public function auth($userID, $credential) {} // todo
    }
    class AuthBackendModel implements AuthBackend {
        private $model;
        private $method;
        public function __construct($model, $method = 'auth') {
            $this->model = '\\model\\' . $model;
            $this->method = $method;
        }
        public function auth($userID, $credential) {
            $user = new $this->model();
            $method = $this->method;
            return $user->$method($userID, $credential);
        }
    }
    class _Auth extends _Singleton {
        protected static $_instance;
        private $backend = false;
        public $user = false;
        private $session_started = false;


        public function setup(AuthBackend $backend) {
            $this->backend = $backend;
            if (_::Session()->started()) {
                if (isset($_SESSION['userID'], $_SESSION['credential'])) {
                    $this->login($_SESSION['userID'], $_SESSION['credential']);
                }
            }
        }
        public function login($userID, $credential) {
            if ($user = $this->backend->auth($userID, $credential)) {
                $this->user = $user;
                _::Session()->start();
                $_SESSION['userID'] = $userID;
                $_SESSION['credential'] = $credential;
                return true;
            }
            return false;
        }
        public function logoff($destroy_session = true) {
            $this->user = false;
            if ($destroy_session) {
                _::Session()->stop();
            }
        }
        public function getRole() {
            return $this->user['role'] ?: 'nobody';
        }
        public function getId() {
            return $this->user['user_id'] ?: false;
        }
    }
    class _ACL extends _Singleton {
        protected static $_instance;
        private $roles;
        private $access;

        public function addRole($role, $extends = false) {
            $this->roles[$role] = $extends;
        }
        public function allow($role, $resource, $action = 'default') {
            if ($parent = $this->roles[$role]) {
                $this->allow($parent, $resource, $action);
            }
            $this->access[$role][$resource][$action] = true;
        }
        public function check($resource, $action = 'default', $role = null) {
            if ($role === null) $role = _::Auth()->getRole();
            return isset($this->access[$role][$resource][$action]);
        }

    }
    class _Registry extends _Singleton implements ArrayAccess {
        protected static $_instance;
        private static $registry = array();

        public static function has($key) {
            return isset(self::$registry[$key]);
        }
        public static function set($key, $value, $override = false) {
            if (!self::has($key) || $override) {
                self::$registry[$key] = $value;
                return true;
            } else {
                return false;
            }
        }
        public static function get($key) {
            if (self::has($key)) {
                return self::$registry[$key];
            }
            return null;
        }
        public static function remove($key) {
            if (self::has($key)) {
                unset(self::$registry[$key]);
                return true;
            }
            return false;
        }
        public function offsetExists($offset) {
            return $this->has($offset);
        }
        public function offsetGet($offset) {
            return $this->get($offset);
        }
        public function offsetSet($offset, $value) {
            $this->set($offset, $value);
        }
        public function offsetUnset($offset) {
            $this->remove($offset);
        }
    }
    class _Config extends _Singleton {
        protected static $_instance;
        private $config = array();
        private $configs = array();
        public function init($options = array()) {
            $this->config = (object)array_merge(array('dir' => './config'), $options);
        }
        public function __get($key) {
            if (isset($this->configs[$key])) {
                return (object)$this->configs[$key];
            } else if (file_exists($file = $this->config->dir . '/' . $key . '.php')) {
                include $file;
                if (DEVELOP && isset(${$key.'_dev'})) {
                    $$key = array_merge($$key, ${$key.'_dev'});
                }
                $this->configs[$key] = ifset($$key, array());
                return (object)$this->configs[$key];
            }
            return false;
        }
    }
    class _Language extends _Singleton {
        protected static $_instance;
        private $config = array();
        private $lang = array();

        public function init($options = array()) {
            $this->config = (object)array_merge(array('dir' => './language', 'autoadd' => true), $options);
        }
        public function load($sh) {
            include $this->config->dir . '/' . $sk . '.php';
            array_unshift($this->lang, $lang);
        }
        public function get($key) {
            foreach ($this->lang as $k => $lang) {
                if (isset($lang[$key]) && $lang[$key] !== false) {
                    return $lang[$key];
                } else if ($k == 0 && count($this->lang) && $this->config->autoadd) {
                    // key does not exist
                    $data = '$lang[\''.$key.'\'] = false;' . "\n";
                    file_put_contents($this->config->dir . "/$lang.php", $data, FILE_APPEND);
                }
            }
            return $key;
        }
        public function getByKey($key) {
            foreach (array_reverse($this->lang) as $k => $lang) {
                if (isset($lang['_'.$key])) {
                    return $lang[$key];
                }
            }
            return null;
        }
    }
    class _Pagination {
        private $config = array();
        private $total = 0;
        public function __construct($options = array()) {
            $this->config = (object)array_merge(array('page' => 1, 'perpage' => 20, 'uri' => ''), $options);
        }
        public function getTotal(ePdo $dbh) {
            list($total) = $dbh->query('SELECT FOUND_ROWS()')->fetch(PDO::FETCH_NUM);
            $this->total = $total;
            return $total;
        }
        public function getLinks($options = array()) {
            $pages = ceil($this->total/$this->config->perpage);
            $page = $this->config->page > 0 && $this->config->page <= $pages ? $this->config->page : 1;

            $links = '';
            for ($i=1; $i<=$pages; $i++) {
                $cur = $i;
                if ($i == $page) {
                    $cur = "<strong>$i</strong>";
                }
                $links .= ' <a href="'.\View\url($this->config->uri, array(), array('page' => $i)).'">'.$cur.'</a> ';
            }
            return $links;
        }
        public function getLimit() {
            $start = ($this->config->page-1) * $this->config->perpage;
            return 'LIMIT ' . $start . ',' . $this->config->perpage;
        }
        public function hasMore() {
            return ceil($this->total/$this->config->perpage) > $this->config->page;
        }
    }
    // select returnt, fetch fetched in das objekt
    abstract class _Model implements \ArrayAccess, \Iterator  {
        protected $dbh = null;
        protected $table = null;
        protected $data = array();
        protected $criteria = false;
        protected $pk = false;
        protected $fetched = false;
        protected $multiIndex = false;
        protected $pagination = false;
        public $code = 0;
        public function __construct($criteria = array()) {
            if (!$this->dbh) {
                die('no handle for model ' . __CLASS__);
            }
            if (!$this->table) {
                $this->table = lower(__CLASS__);
            }
            if ($criteria) {
                $this->setCriteria($criteria);
            }
        }
        public static function i($criteria = array()) {
            return new static($criteria);
        }
        protected function setCriteria($c) {
            if (!is_array($c)) {
                $this->criteria = array($this->pk?:'false' => $c);
            } else {
                $this->criteria = $c;
            }
        }
        public function setPagination($config = array()) {
            $this->pagination = _::Pagination($config);
        }
        public function getPagination() {
            return $this->pagination;
        }
        public function set($data) {
            $this->data = $data;
        }
        public function __set($key, $var) {
            if ($this->multiIndex === false) {
                $this->data[$key] = $var;
            }
        }
        public function __get($key) {
            return ifset($this->data[$key]);
        }
        public function fetch($select = '*') {
            $this->fetched = true;
            try {
                $data = $this->select($this->data, $select, 'fetch');
                // check for NULL and do NULL -> array('NULL')
                /*
                foreach ($data as &$v) {
                    if ($v === null) {
                        $v = array('NULL');
                    }
                }
                */
                $this->data = $data;
                #return true;
            } catch (PDOException $e) {
                echo $e->getMessage();
                #return false;
            }
            return $this;
        }
        public function fetchAll($select = '*', $order = false) {
            $this->fetched = true;
            try {
                $this->data = $this->select($this->data, $select, 'fetchAll', $order);
                $this->multiIndex = 0;
                if ($this->pagination) return $this->pagination->getTotal($this->dbh);
                return true;
            } catch (PDOException $e) {
                echo $e->getMessage();
                return false;
            }
        }

        public function save($data = false) {
            try {
                if ($this->criteria) {
                    $data = $data?:$this->data;
                    if (isset($data[$this->pk])) unset($data[$this->pk]);
                    return $this->dbh->update($this->table, $data, $this->criteria);
                } else {
                    return $this->dbh->insert($this->table, $this->data);
                }
            } catch (PDOException $e) {
                echo $e->getMessage();
                $this->code = $e->getCode();
                return false;
            }
        }
        public function getLastId() {
            return $this->dbh->lastInsertId();
        }
        public function getData() {
            return $this->data;
        }
        public function isValid() {
            return !empty($this->data);
        }
        public function select($data = false, $select = '*', $fetchtype = 'fetch', $order = false) {
            $limit = $this->pagination ? $this->pagination->getLimit() : '';
            return $this->dbh->select($this->table, $data?:$this->criteria, $select, $fetchtype, $order, $limit);
        }
        public function delete($c = false) {
            return $this->dbh->delete($this->table, $c?:$this->criteria);
        }
        public function exists($key, $value) {
            return $this->dbh->exists($this->table, $key, $value);
        }
        // ArrayAccess
        public function offsetSet($offset, $value) { /* read only */ }
        public function offsetUnset($offset) { /* read only */ }
        public function offsetExists($offset) {
            return isset($this->data[$offset]);
        }
        public function offsetGet($offset) {
            return isset($this->data[$offset]) ? $this->data[$offset] : null;
        }
        // Iterator
        function rewind() {
            if ($this->multiIndex !== false) $this->multiIndex = 0;
        }
        function current() {
            return $this->multiIndex !== false ? (object)$this->data[$this->multiIndex] : false;
        }
        function key() {
            return $this->multiIndex !== false ?$this->multiIndex : false;
        }
        function next() {
            if ($this->multiIndex !== false) ++$this->multiIndex;
        }
        function valid() {
            return $this->multiIndex !== false && isset($this->data[$this->multiIndex]);
        }
    }
    class ePDO extends pdo {
        private $queryCount;
        private $queryTime;
        private $queryLog;
        public $debug;

        private $connected;
        private $dsn;
        private $user;
        private $pass;
        private $options;

        function __construct($dsn, $user = NULL, $pass = NULL, $options = NULL) {
            $this->connected = false;
            $this->dsn = $dsn;
            $this->user = $user;
            $this->pass = $pass;
            $this->options = $options;
            $this->queryCount = 0;
            $this->queryTime = 0;
            $this->queryLog = array();
            $this->debug = false;
        }

        private function connect() {
            $this->connected = true;

            parent::__construct($this->dsn, $this->user, $this->pass, $this->options);
            $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('ePDOStatement', array($this)));
            $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

            $this->exec("SET NAMES 'utf8'");
        }
        public function quote($sql, $parameter_type = PDO::PARAM_STR) {
            if (!$this->connected) {
                $this->connect();
            }
            return parent::quote($sql, $parameter_type);
        }
        public function exec($sql) {
            if (!$this->connected) {
                $this->connect();
            }
            $this->increaseQueryCount();
            if ($this->debug) $starttime = microtime(true);
            $res = parent::exec($sql);
            if ($this->debug) $this->logQuery($sql, microtime(true) - $starttime, 'exec');

            return $res;
        }
        public function query($sql, $fetchmode = PDO::FETCH_ASSOC) {
            if (!$this->connected) {
                $this->connect();
            }
            $this->increaseQueryCount();
            if ($this->debug) $starttime = microtime(true);
            $res = parent::query($sql, $fetchmode);
            if ($this->debug) $this->logQuery($sql, microtime(true) - $starttime, 'query');

            return $res;
        }
        public function prepare($sql, $opt = array()) {
            if (!$this->connected) {
                $this->connect();
            }
            return parent::prepare($sql, $opt);
        }
        public function increaseQueryCount() {
            $this->queryCount++;
        }
        public function increaseQueryTime($time) {
            $this->queryTime += $time;
        }
        public function logQuery($sql, $duration, $type) {
            $md5 = md5($sql);
            if (isset($this->queryLog[$md5])) {
                $this->queryLog[$md5]['count']++;

                $this->queryLog[$md5]['duration_ttl'] += $duration;
                if ($duration < $this->queryLog[$md5]['duration_min'])
                    $this->queryLog[$md5]['duration_min'] = $duration;
                if ($duration > $this->queryLog[$md5]['duration_max'])
                    $this->queryLog[$md5]['duration_max'] = $duration;
                $this->queryLog[$md5]['duration_avg'] = $this->queryLog[$md5]['duration_ttl'] / $this->queryLog[$md5]['count'];
            } else {
                $this->queryLog[$md5]['sql'] = $sql;
                $this->queryLog[$md5]['type'] = $type;
                $this->queryLog[$md5]['count'] = 1;
                $this->queryLog[$md5]['duration_ttl'] = $duration;
                $this->queryLog[$md5]['duration_min'] = $duration;
                $this->queryLog[$md5]['duration_max'] = $duration;
                $this->queryLog[$md5]['duration_avg'] = $duration;
                $bt = debug_backtrace(false);
                $this->queryLog[$md5]['source'] = $bt[1];
            }
            $this->increaseQueryTime($duration);
        }

        public function setDebug($debug = true) {
            $this->debug = $debug;
        }
        public function getQueryCount() {
            return $this->queryCount;
        }
        public function getQueryTime() {
            return sprintf('%.4f', $this->queryTime);
        }
        public function getQueryLog() {
            return $this->queryLog;
        }

        public function exists($table, $key, $value) {
            return $this->query("SELECT $key FROM $table WHERE $key = {$this->quote($value)}")->fetch(PDO::FETCH_NUM);
        }
        public function select($table, $args, $select = '*', $fetchtype = 'fetch', $order = '', $limit = '') {
            $where = $this->buildArgs($args, ' AND ');
            $opt = $limit ? 'SQL_CALC_FOUND_ROWS' : '';
            $order = $order ? "ORDER BY $order" : '';
            $stmt = $this->prepare($sql = "SELECT $opt $select FROM $table WHERE $where $order $limit");
            $stmt->execute($args);

            return $stmt->$fetchtype(PDO::FETCH_ASSOC);
        }
        public function insert($table, $data) {
            $set = $this->buildArgs($data);
            return $this->prepare("INSERT INTO $table SET $set")->execute($data);
        }
        public function update($table, $data, $c) {
            #if (!$pk || !isset($data[$pk])) return $this->insert($table, $data);

            $where = $this->buildArgs($c, ' AND ');
            $set = $this->buildArgs($data);
            return $this->prepare($sql = "UPDATE $table SET $set WHERE $where")->execute($c+$data);
        }
        public function delete($table, $c) {
            $where = $this->buildArgs($c, ' AND ');
            return $this->prepare("DELETE FROM $table WHERE $where")->execute($c);
        }

        public function buildArgs($data, $sep =', ') {
            $set = array();
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    $left = ifset($v[2], $k);
                    $right = ifset($v[0], ':'.$k);
                    if ($right === false) {
                        $set[] = $left;
                    } else {
                        $set[] = "$left = $right";
                    }
                #} else if ($v === null) {
                #    $set[] = $k;
                } else {
                    $set[] = "$k = :$k";
                }
            }
            return implode($sep, $set);
        }
    }
    class ePDOStatement extends PDOStatement {
        protected function __construct(ePDO $pdo) {
            $this->pdo = $pdo;
        }
        public function execute($params = null) {
            $this->pdo->increaseQueryCount();
            if ($this->pdo->debug) $starttime = microtime(true);

            if ($params) foreach ($params as $k => &$v) {
                if (is_array($v)) {
                    $value = ifset($v[1]);
                    if ($value === null) {
                        unset($params[$k]);
                    } else if (is_array($value)) {
                        unset($params[$k]);
                        foreach ($value as $key => $val) {
                            $params[$key] = $val;
                        }
                    } else {
                        $v = $value;
                    }
                } else if ($v === null) {
                    unset($params[$k]);
                }
            }

            if (isset($v)) unset($v);
            if (!$params)
                $res = parent::execute();
            else
                $res = parent::execute($params);

            if ($this->pdo->debug) $this->pdo->logQuery($this->queryString, microtime(true) - $starttime, 'execute');

            return $res;
        }
    }
    class _Hook extends _Singleton {
        protected static $_instance;
        protected $hooks = array();
        protected $options;
        protected function init($options = array()) {
            $this->options = (object)array_merge(array('dir' => './hooks'), $options);
        }
        public function run($env, $event, $type = 'pre') {
            if (isset($this->hooks[$env][$event][$type])) {
                if (is_callable($lambda = $this->hooks[$env][$event][$type])) {
                    $lambda($env, $event, $type);
                } else if (file_exists($file=$this->options->dir . '/' . $env . '_' . $event . '_' . $type . '.php')) {
                    include $file;
                }
            }
        }
        public function add(array $runopt, $lambda = false) {
            $env = $runopt[0];
            $event = ifset($runopt[1], 'none');
            $type = ifset($runopt[2], 'pre');
            $this->hooks[$env][$event][$type] = $lambda;
        }
        public function clear($env, $event, $type) {
            if (isset($this->hooks[$env][$event][$type])) {
                unset($this->hooks[$env][$event][$type]);
            }
        }
    }
}
namespace View {
    Use \_;
    // poor man's gettext
    function _($key, $return = false) {
        if ($return) {
            return _::Language()->get($key);
        } else {
            echo _::Language()->get($key);
        }
    }
    function __($key, $return = false) {
        if ($return) {
            return _::Language()->getByKey($key);
        } else {
            echo _::Language()->getByKey($key);
        }
    }
    function url($url, $params = array(), $qs_params = array()) {
        if (preg_match('#^(https?:)?//#', $url)) return $url;
		$ext = _::Router()->getExt() ;
		if ($url[strlen($url)-1] == '/' || preg_match('#\..{2,5}$#i', $url)) $ext = '';
        $path = array();
        if ($params) {
            if (is_array($params)) {
                foreach ($params as $k => $param) {
                    if (is_numeric($k)) {
                        $path[] = $param;
                    } else {
                        $path[] = $k.'/'.$param;
                    }
                }
            } else {
                $path[] = $params;
            }
        }
        $path = $path ? '/'.implode('/', $path) : '';

        $qs = array();
        if ($qs_params) foreach ($qs_params as $k => $v) {
            if (is_numeric($k)) {
                $qs[] = $v;
            } else {
                $qs[] = $k . '=' . $v;
            }
        }
        $qs = $qs ? '?'.implode('&amp;', $qs) : '';

        return rtrim(_::Request()->relpath . '/' . $url . $path . $ext, '/') . $qs;
    }
    function align($left, $right) {
        return "<span class='fll'>$left</span><span class='flr'>$right</span>";
        $fill = 79 - len($left) - len($right);
        return $left . str_repeat(' ', $fill) . $right;
    }
}
namespace Model {
    Use \_;
    // camoPK
    function camoPK($pk) {
        // uh oh
    }
}
namespace _ {
    // sprintf /w named params
    function sprintf($str) {
        return 'foo!';
    }
}
namespace {
    // non-namespaced bare-essentials
    function ifset(&$var, $default = null, $true = false) {
        return isset($var) ?
            ($true ? sprintf($true, $var) : $var) :
            $default;
    }
    // e as in escape
    function e($string) {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
    function nf($number, $dec = 0) {
        return number_format($number, $dec, ',', '.');
    }
    // Function aliases
    function len($string) { return mb_strlen($string); }
    function lower($string) { return mb_strtolower($string); }
    function upper($string) { return mb_strtoupper($string); }
    function ss() { return call_user_func_array('substr', func_get_args()); }
    function instr($string, $needle) {
        return strpos($string, $needle) !== false;
    }

    function flatten($array, $key) {
        #$datenarray = new RecursiveArrayIterator( $daten );
        #$iterator   = new RecursiveIteratorIterator( $datenarray, TRUE );
        $flat = array();
        foreach ($array as $k => $v) {
            if (isset($v[$key])) {
                $flat[] = $v[$key];
            }
        }
        return $flat;
    }

    spl_autoload_register('_::autoload');
}
