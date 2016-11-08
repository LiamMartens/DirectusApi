<?php
    /**
    * DEPENDS ON
    * php redis extension
    * tomaj/hermes
    */
    use Tracy\Logger;
    use Tomaj\Hermes\Message;
    use Tomaj\Hermes\Emitter;
    use Tomaj\Hermes\Dispatcher;
    use Tomaj\Hermes\MessageInterface;
    use Tomaj\Hermes\Driver\RedisSetDriver;
    use Tomaj\Hermes\Handler\HandlerInterface;

    class Directus {
        const VALUE_EXPIRED = 1;

        protected static $_redis = false;
        protected static $_source = '';
        protected static $_token = '';
        protected static $_cachetime = 3600;

        /**
        * Checks whether redis is enabled
        *
        * @return boolean
        */
        public static function isRedisEnabled() {
            return (self::$_redis!==false);
        }

        /**
        * Gets redis instance
        *
        * @return Redis|boolean
        */
        public static function redis() {
            return self::$_redis;
        }

        /**
        * Sets a cache key in file
        *
        * @param string $key
        * @param array $value
        *
        * @return boolean
        */
        private static function setCacheFile($key, $value) {
            // get file path
            $cache_dir = __DIR__.'/cache/';
            $cache_file = $cache_dir.hash("sha512", $key);

            // check file / directory writable
            if(
                (is_file($cache_file)&&is_writable($cache_file))||
                (!is_file($cache_file)&&is_writable($cache_dir))
            ) {
                // wrap value
                $value = [
                    "time" => time(),
                    "data" => $value
                ];

                // write to file
                return (file_put_contents($cache_file, json_encode($value))>0);
            }
            return false;
        }

        /**
        * Retrieves a key from cache file
        *
        * @param string $key
        * @param boolean ref $is_expired
        *
        * @return boolean|array
        */
        private static function getCacheFile($key, &$is_expired=null) {
            // get file path
            $cache_dir = __DIR__.'/cache/';
            $cache_file = $cache_dir.hash("sha512", $key);

            // if not file -> key doesn't exist
            if(!is_file($cache_file)) {
                $is_expired = true;
                return false;
            }

            $data = json_decode(file_get_contents($cache_file), true);

            if(!is_array($data)) {
                $is_expired = true;
                return false;
            }

            if(time()-$data['time']<self::$_cachetime) {
                // not expired
                $is_expired = false;
                return $data['data'];
            }
            $is_expired = true;
            return $data['data'];
        }

        /**
        * Sets a cache key in redis
        *
        * @param string $key
        * @param array $value
        *
        * @return boolean
        */
        private static function setCacheRedis($key, $value) {
            if(self::$_redis!==false) {
                // wrap value
                $value = [
                    "time" => time(),
                    "data" => $value
                ];

                return self::$_redis->set($key, json_encode($value));
            }
            return false;
        }

        /**
        * Retrieves a key from cache redis
        *
        * @param string $key
        * @param boolean ref $is_expired
        *
        * @return boolean|array
        */
        private static function getCacheRedis($key, &$is_expired=null) {
            if(self::$_redis!==false) {
                $data = json_decode(self::$_redis->get($key), true);

                if(!is_array($data)) {
                    $is_expired = true;
                    return false;
                }

                if(time()-$data['time']<self::$_cachetime) {
                    // not expired
                    $is_expired = false;
                    return $data['data'];
                }
                $is_expired = true;
                return $data['data'];
            }
            $is_expired = true;
            return false;
        }

        /**
        * Retrieves a cache value
        *
        * @param string $key
        * @param mixed $value
        *
        * @return mixed
        */
        private static function getCache($path, &$is_expired=null) {
            if(self::$_redis!==false) {
                return self::getCacheRedis($path, $is_expired);
            }
            return self::getCacheFile($path, $is_expired);
        }

        /**
        * Sets a cache key value
        *
        * @param string $key
        * @param array $value
        *
        * @return mixed
        */
        private static function setCache($path, $value) {
            if(self::$_redis!==false) {
                return self::setCacheRedis($path, $value);
            }
            return self::setCacheFile($path, $value);
        }

        /**
        * Sets the source and token
        *
        * @param array
        */
        public static function config($config) {
            if(isset($config['source'])) {
                self::$_source = $config['source'];
            }
            if(isset($config['token'])) {
                self::$_token = $config['token'];
            }

            // check for redis
            if(self::$_redis===false) {
                self::$_redis = new Redis();
            }

            if(isset($config['redis_host'])&&isset($config['redis_port'])) {
                // close old connection
                try {
                    self::$_redis->ping();
                    self::$_redis->close();
                } catch(RedisException $e) {}

                // open new connection
                self::$_redis->connect($config['redis_host'], $config['redis_port']);
            }
        }

        /**
        * Updates an api endpoint
        *
        * @param string $path
        */
        public static function update($path) { 
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::$_source.$path);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer '.self::$_token]);
            self::setCache($path, json_decode(curl_exec($ch), true));
            curl_close($ch);
        }

        /**
        * Retrieve information from directus API endpoint
        *
        * @param string $path
        *
        * @return json
        */
        public static function fetch($path) {
            $is_expired = false;
            $data = self::cache($path, $is_expired);
            // data doesn't exist
            if($data===false) {
                // update synchron
                self::update($path);
                return self::cache($path);
            } elseif(($is_expired)&&(self::$_redis!==false)) {
                // update async using hermes
                $driver = new RedisSetDriver(self::$_redis);
                $emitter = new Emitter($driver);
                $message = new Message('directus-update-endpoint', [
                    'path' => $path
                ]);
                $emitter->emit($message);
            } elseif($is_expired) {
                // run using exec in background
                exec(__FILE__.' --update-exec='.addslashes($path).' > /dev/null &');
            }
            return $data;
        }

        /**
        * Post synchronously
        *
        * @param string $path
        * @param array $data
        */
        public static function post($path, $data) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::$_source.$path);
            curl_setopt($ch, CURLOPT_POST, count($data));
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer '.self::$_token,
                'Content-Type: application/json']); 
            $output=curl_exec($ch);
            curl_close($ch);
            return json_decode($output, true);
        }
    }

    class DirectusHandler implements HandlerInterface {
        public function handle(MessageInterface $message) {
            $payload = $message->getPayload();
            if(!isset($payload['path'])) {
                echo 'No path specified';
            }
            echo 'Updating '.$payload['path'].' on '.date('d-M-Y H:i:s');
            Directus::update($payload['path']);
            echo 'Updated '.$payload['path'].' on '.date('d-M-Y H:i:s');
        }
    }

    // include config file if exists
    if(is_file(__DIR__.'/config.php')) {
        require(__DIR__.'/config.php');
    }

    // check argv's
    foreach($argv as $arg) {
        // start listening for messages
        if(($arg=='--start')&&(Directus::isRedisEnabled())) {
            $logger = new Logger(__DIR__.'/log');
            $driver = new RedisSetDriver(Directus::redis());
            $dispatcher = new Dispatcher($driver, $logger);
            $dispatcher->registerHandler('directus-update-endpoint', new DirectusHandler());
            // start listening
            $dispatcher->handle();
            continue;
        }
        // update using exec statement
        if(substr($arg, 0, strlen('--update-exec=')=='--update-exec')) {
            $path = stripslashes(substr($arg, strlen('--update-exec=')+1));
            echo 'Updating '.$path.' on '.date('d-M-Y H:i:s');
            Directus::update($path);
            echo 'Updated '.$path.' on '.date('d-M-Y H:i:s');
            continue;
        }
    }