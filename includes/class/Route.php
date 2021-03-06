<?php
    /**
     * Class: Route
     * Handles the routing process and other route-related tasks.
     */
    class Route {
        # String: $action
        # The current action.
        public $action = "";

        # String: $request
        # The extracted request.
        public $request = "";

        # Array: $arg
        # Clean URLs decomposed into an array of arguments.
        public $arg = array();

        # Array: $try
        # An array of actions to try until one doesn't return false.
        public $try = array();

        # Boolean: $ajax
        # Shortcut to the AJAX constant (useful for Twig).
        public $ajax = AJAX;

        # Boolean: $success
        # Did <Route.init> call a successful route?
        public $success = false;

        # Variable: $controller
        # The Route's Controller.
        public $controller;

        /**
         * Function: __construct
         * Parse the URL and give the controller an opportunity to determine the action.
         *
         * Parameters:
         *     $controller - The controller to use.
         */
        private function __construct($controller) {
            if (!in_array("Controller", class_implements($controller)))
                trigger_error(__("Route was initiated with an invalid Controller."), E_USER_ERROR);

            $config = Config::current();

            fallback($controller->protected, array("__construct", "__destruct", "parse", "display"));
            fallback($controller->permitted, array("login", "logout"));
            fallback($controller->feed, isset($_GET['feed']));
            fallback($controller->displayed, false);

            # Set the contoller for this route.
            $this->controller = $controller;

            # Determining the action can be this simple if clean URLs are disabled.
            $this->action =& $_GET['action'];

            $base = empty($controller->base) ? $config->url : $config->chyrp_url."/".$controller->base ;
            $regex = "~^".preg_quote(oneof(parse_url($base, PHP_URL_PATH), ""), "~")."((/)index.php)?~";

            # Extract the request.
            $this->request = preg_replace($regex, "$2", $_SERVER['REQUEST_URI']);

            # Decompose clean URLs.
            $this->arg = array_map("urldecode", explode("/", trim($this->request, "/")));

            # Give the controller an opportunity to parse this route and determine the action.
            $controller->parse($this);

            Trigger::current()->call("parse_route", $this);

            # Support single parameter actions without custom routes or parsing by the controller.
            if (empty($this->action) and !empty($this->arg[0]))
                $this->try[] = $this->arg[0];
        }

        /**
         * Function: init
         * Attempt to call a responder for the action(s) until one of them doesn't return false.
         *
         * This will also call the @[controllername]_[action]@ and @route_[action]@ triggers.
         */
        public function init() {
            $trigger = Trigger::current();
            $visitor = Visitor::current();

            $trigger->call("route_init", $this);

            if (isset($this->action))
                array_unshift($this->try, $this->action);

            foreach ($this->try as $key => $val) {
                list($method, $args) = is_numeric($key) ? array($val, array()) : array($key, $val) ;

                $this->action = $method;

                # Don't try to call anything except a valid PHP function.
                if (preg_match("/[^\w]/", $this->action))
                    error(__("Error"), __("Invalid action."), null, 400);

                # Show a 403 page if the visitor cannot view the site and this is not an exempt action.
                if (!$visitor->group->can("view_site") and
                    !in_array($this->action, $this->controller->permitted)) {

                    $trigger->call("can_not_view_site");
                    show_403(__("Access Denied"), __("You are not allowed to view this site."));
                }

                $name = strtolower(str_replace("Controller", "", get_class($this->controller)));

                # This discovers responders provided by extensions.
                if ($trigger->exists($name."_".$method) or $trigger->exists("route_".$method))
                    $call = $trigger->call(array($name."_".$method, "route_".$method), $this->controller);
                else
                    $call = false;

                if ($call !== false) {
                    $this->success = true;
                    break;
                }

                # Protect the controller's non-responder methods. PHP functions are not case-sensitive!
                foreach ($this->controller->protected as $protected)
                    if (strcasecmp($protected, $method) == 0)
                        continue 2;

                # This discovers responders native to the controller.
                if (method_exists($this->controller, $method))
                    $response = call_user_func_array(array($this->controller, $method), $args);
                else
                    $response = false;

                if ($response !== false) {
                    $this->success = true;
                    break;
                }
            }

            # No responders were found; display a failpage if one is set.
            if (!$this->success and method_exists($this->controller, "failed"))
                $this->controller->failed($this);

            # Show a 404 page if routing failed and nothing was displayed.
            if (!$this->success and !$this->controller->displayed)
                show_404();

            # Set redirect_to so that visitors will come back here after login.
            if (!in_array($this->action, $this->controller->permitted))
                $_SESSION['redirect_to'] = self_url();

            $trigger->call("route_done", $this);

            return $this->success;
        }

        /**
         * Function: url
         * Constructs an absolute URL from a relative one, translating clean to dirty URLs as necessary.
         *
         * The applicable URL translations are filtered through the @parse_urls@ trigger.
         *
         * Parameters:
         *     $url - The relative URL. This is assumed to be a dirty URL if it begins with "/".
         *     $controller - The controller to use. If omitted the current controller will be used.
         *
         * Returns:
         *     An absolute clean or dirty URL, depending on @Config->clean_urls@ and controller support.
         */
        static function url($url, $controller = null) {
            $config = Config::current();

            if (!isset($controller))
                $controller = Route::current()->controller;

            if (is_string($controller))
                $controller = $controller::current();

            $base = empty($controller->base) ? $config->url : $config->chyrp_url."/".$controller->base ;

            # Assume this is a dirty URL and return it without translation.
            if (strpos($url, "/") === 0)
                return fix($base.$url, true);

            # Assume this is a clean URL and ensure it ends with a slash.
            $url = rtrim($url, "/")."/";

            # Translation is unnecessary if clean URLs are enabled and the controller supports them.
            if ($config->clean_urls and !empty($controller->clean))
                return fix($base."/".$url, true);

            $urls = fallback($controller->urls, array());

            Trigger::current()->filter($urls, "parse_urls");

            # Generate a feed variant of all dirty translations not native to the controller.
            foreach (array_diff_assoc($urls, $controller->urls) as $key => $value) {
                $delimiter = substr($key, 0, 1);
                $urls[substr($key, 0, -1).preg_quote("feed/", $delimiter).$delimiter] = $value."&amp;feed";
            }

            # Add a fallback for single parameter translations.
            $urls['|/([^/]+)/$|'] = '/?action=$1';

            return fix($base.preg_replace(array_keys($urls), array_values($urls), "/".$url, 1), true);
        }

        /**
         * Function: add
         * Adds a route to the blog. Required for actions that have more than one parameter.
         * For example, for /tags/ you won't need to do this, but you will for /tag/(name)/.
         *
         * Parameters:
         *     $path - The path to add. Wrap variables with parentheses e.g. "tag/(name)/".
         *     $action - The action. Add parameters with semicolons e.g "tag;foo=bar;baz=boo".
         *
         * See Also:
         *     <remove>
         */
        public function add($path, $action) {
            $config = Config::current();

            $new_routes = $config->routes;
            $new_routes[$path] = $action;

            $config->set("routes", $new_routes);
        }

        /**
         * Function: remove
         * Removes a route added by <add>.
         *
         * Parameters:
         *     $path - The path to remove.
         *
         * See Also:
         *     <add>
         */
        public function remove($path) {
            $config = Config::current();

            unset($config->routes[$path]);

            $config->set("routes", $config->routes);
        }

        /**
         * Function: custom
         * Parses custom routes stored in the configuration.
         *
         * Notes:
         *     The / path strictly requires an empty request.
         */
        public function custom() {
            $config = Config::current();

            foreach ($config->routes as $path => $action) {
                preg_match_all("/\(([^\)]+)\)/", $path, $variables);

                $escape = preg_quote(oneof(trim($path, "/"), "/"), "/");
                $regexp = ($path == "/") ?
                          "\$" :
                          preg_replace("/\\\\\(([^\)]+)\\\\\)/", "([^\/]+)", $escape) ;

                # Expression matches?
                if (preg_match("/^\/{$regexp}/", $this->request, $args)) {
                    array_shift($args);

                    # Populate $_GET variables discovered in the path.
                    if (isset($variables[1]))
                        foreach ($variables[1] as $index => $variable)
                            $_GET[$variable] = urldecode($args[$index]);

                    # Populate $_GET variables contained in the action.
                    $params = explode(";", $action);
                    $action = $params[0];

                    array_shift($params);

                    foreach ($params as $param) {
                        $split = explode("=", $param);
                        fallback($split[1], "");
                        $_GET[$split[0]] = urldecode($split[1]);
                    }

                    # Set the action.
                    $this->action = $action;
                }
            }
        }

        /**
         * Function: current
         * Returns a singleton reference to the current class.
         */
        public static function & current($controller = null) {
            static $instance = null;

            if (!isset($controller) and empty($instance))
                trigger_error(__("Route was initiated without a Controller."), E_USER_ERROR);

            $instance = (empty($instance)) ? new self($controller) : $instance ;
            return $instance;
        }
    }

