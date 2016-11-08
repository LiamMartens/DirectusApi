# DirectusApi
Small library for directus API

## Install dependencies
The `Directus` library uses [tomaj/hermes](https://github.com/tomaj/hermes) so make sure to install it (if you want to use Redis for caching, otherwise file caching is used).

## Config
Update the `config.php` file with the correct information
```
    Directus::config([
        'source' => 'API URL',
        'token' => 'API TOKEN',
        'redis_host' => 'IP',
        'redis_port' => 6379
    ]);
```

## Run the redis daemon
To be able to cache data in the background you have to run the daemon using following command:  
`php Directus.php --start`

## Methods
* `Directus::fetch('path')` : fetches information using a CURL call. Also checks/updates cache.
* `Directus::post('path', array $data)` : post data to the API and get a JSON back. This runs synchronously.