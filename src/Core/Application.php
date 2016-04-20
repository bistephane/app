<?php
/**
 * Create and maintener by diagnostic developpers teams:
 * 
 * @author Etchien Boa <geekroot9@gmail.com>
 * @author Dakia Franck <dakiafranck@gmail.com>
 * @package Bow\Core
 */

namespace Bow\Core;

use Bow\Support\Util;
use Bow\Http\Request;
use Bow\Http\Response;
use Bow\Support\Logger;
use InvalidArgumentException;
use Bow\Exception\ApplicationException;

class Application
{
	/**
	 * Définition de contrainte sur un route.
	 *
	 * @var array
	 */ 
	private $with = [];

	/**
	 * Branchement global sur un liste de route
	 * 
	 * @var string
	 */
	private $branch = "";

	/**
	 * @var string
	 */
	private $specialMethod = null;
	
	/**
	 * Fonction lancer en cas d'erreur.
	 * 
	 * @var null|callable
	 */
	private $error404 = null;

	/**
	 * Method Http courrante.
	 * 
	 * @var string
	 */
	private $currentMethod = "";
	/**
	 * Enrégistre l'information la route courrante
	 * 
	 * @var string
	 */
	private $currentPath = "";

	/**
	 * Patter Singleton
	 * 
	 * @var Application
	 */
	private static $inst = null;

	/**
	 * Collecteur de route.
	 *
	 * @var array
	 */
	private static $routes = [];

	/**
	 * @var Request
	 */
	private $req;

	/**
	 * @var AppConfiguration|null
	 */
	private $config = null;

    /**
     * @var array
     */
	private $local = [];

	/**
	 * Private construction
	 *
	 * @param AppConfiguration $config
	 */
	private function __construct(AppConfiguration $config)
	{
		$this->req = $this->request()->method();
		$this->config = $config;
        $this->req = $this->request();

		$logger = new Logger($config->getLogLevel(), $config->getLogpath() . "/error.log");
		$logger->register();
	}

	/**
	 * Private __clone
	 */
	private function __clone(){}

	/**
	 * Pattern Singleton.
	 * 
	 * @param AppConfiguration $config
	 * @return Application
	 */
	public static function configure(AppConfiguration $config)
	{
		if (static::$inst === null) {
			static::$inst = new static($config);
		}

		return static::$inst;
	}

	/**
	 * mount, ajoute un branchement.
	 *
	 * @param string $branch
	 * @param callable $cb
	 * @throws ApplicationException
	 * @return Application
	 */
	public function group($branch, $cb)
	{
		$this->branch = $branch;

		if (is_array($cb)) {
			Util::launchCallback($cb, $this->req, $this->config->getNamespace());
		} else {
			if (!is_callable($cb)) {
				throw new ApplicationException("Callback are not define", E_ERROR);
			}
			call_user_func_array($cb, [$this->req]);
		}

        $this->branch = "";

		return $this;
	}

	/**
	 * get, route de type GET ou bien retourne les variable ajoutés dans Bow
	 *
	 * @param string $path
	 * @param callable $cb
	 * @return Application|string
	 */
	public function get($path, $cb = null)
	{
		if ($cb === null) {
			$key = $path;
            if (in_array($key, $this->local)) {
               return $this->local[$key];
            } else {
                if (($method = $this->getConfigMethod($key, "get")) !== false) {
                    return $this->config->$method();
                } else {
                    return null;
                }
            }
		}

        return $this->routeLoader("GET", $this->branch . $path, $cb);
    }

	/**
	 * post, route de type POST
	 *
	 * @param string $path
	 * @param callable $cb
	 * @return Application
	 */
	public function post($path, $cb)
	{
		$body = $this->req->body();

		if ($body->has("method")) {
			$this->specialMethod = $method = strtoupper($body->get("method"));
			if (in_array($method, ["DELETE", "PUT"])) {
				$this->addHttpVerbe($method, $this->branch . $path, $cb);
			}
			return $this;
		}
		
		return $this->routeLoader("POST", $this->branch . $path, $cb);
	}

	/**
	 * any, route de tout type GET|POST|DELETE|PUT
	 *
	 * @param string $path
	 * @param callable $cb
	 * @return Application
	 */
	public function any($path, $cb)
	{
        foreach(["post", "delete", "put", "get"] as $function) {
            $this->$function($path, $cb);
        }

		return $this;
	}

	/**
	 * delete, route de tout type DELETE
	 *
	 * @param string $path
	 * @param callable $cb
	 * @return Application
	 */
	public function delete($path, $cb)
	{
		return $this->addHttpVerbe("DELETE", $path, $cb);
	}

	/**
	 * put, route de tout type PUT
	 *
	 * @param string $path
	 * @param callable $cb
	 * @return Application
	 */
	public function put($path, $cb)
	{
		return $this->addHttpVerbe("PUT", $path, $cb);
	}

	/**
	 * to404, Charge le fichier 404 en cas de non
	 * validite de la requete
	 *
	 * @param callable $cb
	 * @return Application
	 */
	public function to404($cb)
	{
		$this->error404 = $cb;
		return $this;
	}

	/**
	 * match, route de tout type de method
	 *
	 * @param array $methods
	 * @param string $path
	 * @param callable $cb
	 * @return Application
	 */
	public function match(array $methods, $path, $cb)
	{
		foreach($methods as $method) {
			if ($this->req->method() === strtoupper($method)) {
				$this->routeLoader($this->req->method(), $this->branch . $path , $cb);
			}
		}

		return $this;
	}

	/**
	 * addHttpVerbe, permet d'ajouter les autres verbes http
	 * [PUT, DELETE, UPDATE, HEAD]
	 *
	 * @param string $method
	 * @param string $path
	 * @param callable $cb
	 * @return self
	 */
	private function addHttpVerbe($method, $path, $cb)
	{
		$body = $this->req->body();
		$flag = true;

		if ($body !== null) {
			if ($body->has("method")) {
				if ($body->get("method") === $method) {
					$this->routeLoader($this->req->method(), $this->branch . $path, $cb);
				}
				$flag = false;
			}
		}

		if ($flag) {
			$this->routeLoader($method, $this->branch . $path, $cb);
		}

		return $this;
	}

	/**
	 * routeLoader, lance le chargement d'une route.
	 *
	 * @param string $method
	 * @param string $path
	 * @param callable|array $cb
	 * @return Application
	 */
	private function routeLoader($method, $path, $cb)
	{
		static::$routes[$method][] = new Route($this->config->getApproot() . $path, $cb);

		$this->currentPath = $this->config->getApproot() . $path;
		$this->currentMethod = $method;

		return $this;
	}

	/**
	 * Lance une personnalisation de route.
	 * 
	 * @param array $otherRule
	 * @return Application
	 */
	public function where(array $otherRule)
	{
		if (empty($this->with)) {
			$this->with[$this->currentMethod] = [];
			$this->with[$this->currentMethod][$this->currentPath] = $otherRule;
		} else {
			if (array_key_exists($this->currentMethod, $this->with)) {
				$this->with[$this->currentMethod] = array_merge(
					$this->with[$this->currentMethod], 
					[$this->currentPath => $otherRule]
				);
			}
		}

		return $this;
	}

	/**
	 * Lanceur de l'application
	 * 
	 * @param callable|null $cb
	 * @return mixed
	 */
	public function run($cb = null)
	{
		$this->response()->setHeader("X-Powered-By", "Bow Framework");
		$error = true;

		if (is_callable($cb)) {
			call_user_func_array($cb, [$this->req]);
		}

		$this->branch = "";
		$method = $this->req->method();

		if ($method == "POST") {
			if ($this->specialMethod !== null) {
				$method = $this->specialMethod;
			}
		}

		if (isset(static::$routes[$method])) {
			foreach (static::$routes[$method] as $key => $route) {

				if (! ($route instanceof Route)) {
					break;
				}

				if (isset($this->with[$method][$route->getPath()])) {
					$with = $this->with[$method][$route->getPath()];
				} else {
					$with = [];
				}

                // Lancement de la recherche de la method qui arrivée dans la requete
                // ensuite lancement de la verification de l'url de la requete
                // execution de la fonction associé à la route.
				if ($route->match($this->req->uri(), $with)) {
					$this->currentPath = $route->getPath();
					$response = $route->call($this->req, $this->config->getNamespace());
					if (is_string($response)) {
						$this->response()->send($response);
					} else if (is_array($response) || is_object($response)) {
						$this->response()->json($response);
					}

					$error = false;
				}
			}
		}

        // Si la route n'est pas enrégistre alors on lance une erreur 404
		if ($error === true) {
			$this->response()->setCode(404);
			if (is_callable($this->error404)) {
				call_user_func($this->error404);
			}
		}

		return $error;
	}

	/**
	 * Set, permet de rédéfinir la configuartion
	 *
	 * @param string $key
	 * @param string $value
	 * @throws InvalidArgumentException
     * @return Application|string
	 */
	public function set($key, $value)
	{
        $method = $this->getConfigMethod($key, "set");

        // Vérification de l
		if ($method !== false) {
			if (method_exists($this->config, $method)) {
				return $this->config->$method($value);
			}
		} else {
            $this->local[$key] = $value;
		}

        return $this;
	}

	/**
	 * response, retourne une instance de la classe Response
	 * 
	 * @return Response
	 */
	private function response()
	{
		return Response::configure($this->config);
	}

	/**
	 * request, retourne une instance de la classe Request
	 * 
	 * @return Request
	 */
	private function request()
	{
		return Request::configure();
	}

	/**
	 * __call fonction magic php
	 * 
	 * @param string $method
	 * @param array $param
	 * @throws ApplicationException
	 * @return mixed
	 */
	public function __call($method, $param)
	{
		if (method_exists($this->config, $method)) {
			return call_user_func_array([$this->config, $method], $param);
		}

        throw new ApplicationException("$method not exists.", E_ERROR);
	}

	/**
	 * @return mixed
	 */
	public function url()
	{
		return $this->currentPath;
	}

    /**
     * @param string $key
     * @param string $prefix
     * @return string|bool
     */
    private function getConfigMethod($key, $prefix)
    {
        switch ($key) {
            case "view":
                $method = "Viewpath";
                break;
            case "engine":
                $method = "Engine";
                break;
            case "root":
                $method = "Approot";
                break;
            default:
                $method = false;
                break;
        }

        return is_string($method) ? $prefix . $method : $method;
    }
}