<?php
namespace Scriptburn\Cache;

use \GuzzleHttp\Client;
use \phpFastCache\CacheManager;

class Http
{
    private $cache, $logger;
    public function __construct($cachePath, $logger = null)
    {
        //p_d(rtrim($cachePath, "/"));
        $this->logger = $logger;
        $this->cache  = CacheManager::getInstance('files', ["path" => rtrim($cachePath, "/")]);
    }

    public function log($message)
    {
        if (is_null($this->logger))
        {
            return;
        }
        $this->logger->info($message);
    }
    public function request($action, $url, $data = [], $options = [])
    {
        try
        {
            $default_options = [\GuzzleHttp\RequestOptions::HEADERS => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36',
            ]];
            if (trim(strtolower($action)) == 'post')
            {
                if (empty($options[\GuzzleHttp\RequestOptions::JSON]))
                {
                    $options[\GuzzleHttp\RequestOptions::JSON] = $data;
                }
            }
            $options = array_merge($default_options, $options);

            $client   = new \GuzzleHttp\Client();
            $response = $client->request(trim(strtolower($action)), $url, $options);

            $result['status']   = true;
            $result['body']     = (string) $response->getBody()->getContents();
            $result['code']     = $response->getStatusCode();
            $result['response'] = $response;

        }
        catch (\Exception $e)
        {

            if ($e instanceof GuzzleHttp\Exception\ClientException
                || $e instanceof GuzzleHttp\Exception\RequestException
                || $e instanceof \InvalidArgumentException
                || $e instanceof GuzzleHttp\Exception\RuntimeException
                || $e instanceof GuzzleHttp\Exception\ConnectException
                || $e instanceof \Exception
            )
            {
                $response = $e->getResponse();
            }

            $result = [
                'status'   => false,
                'body'     => is_null($response) ? '' : (string) $response->getBody()->getContents(),
                'response' => $response,
                'code'     => is_null($response) ? 0 : $response->getStatusCode(),
                'error'    => $e,
                'message'  => $e->getMessage(),
            ];
        }
        return (object) $result;
    }

    public function parseCacheOptions($action, $url, $options = [])
    {
        $default_cache_data['cache'] = ['expire' => 0, 'enabled' => 1, 'reset' => 0, 'signature' => ''];

        if (isset($options['cache']))
        {
            if (!is_array($options['cache']))
            {
                if ($options['cache'] === false)
                {
                    $options['cache']['enabled'] = false;
                }
                elseif ((int) $options['cache'])
                {
                    $options['cache']['expire'] = (int) $options['cache'];
                }
            }
        }
        else
        {
            $options['cache'] = [];
        }
        if (strtolower(trim($action)) != 'get')
        {
            $options['cache']['enabled'] = false;
        }
        $cache_data = array_merge($default_cache_data['cache'], $options['cache']);
        if (empty($cache_data['key']))
        {
            $cache_data['key'] = md5($url);
        }
        $cache_data['expire'] = (int) $cache_data['expire'];
        return $cache_data;
    }

    public function mayCache($action, $resource, $options)
    {
        $cache_options = $this->parseCacheOptions($action, $resource, $options);

        $cached_item      = $this->cache->getItem($cache_options['key']);
        $cached_item_data = $cached_item->get();
        $is_cached        = true;
        if (isset($cache_options['reset']) && $cache_options['reset'] && !is_null($cached_item_data))
        {
            $tm = microtime(true);
            $this->log("Doing cache reset for url $resource ");
            $this->cache->deleteItem($cache_options['key']);
            $this->log("Cache reset done in " . (number_format(microtime(true) - $tm, 2)));
            $is_cached = false;
        }
        if ($cache_options['enabled'] && $is_cached && !is_null($cached_item_data))
        {
            $cached_item = null;
            $this->log("Cache found for url $resource ");
            return (object) ['status' => 1, 'body' => $cached_item_data];
        }
        else
        {
            return function ($result) use ($resource, $cache_options)
            {

                if (!$cache_options['enabled'])
                {
                    return $result;
                }
                if (!empty($cache_options['signature']))
                {

                    if (stripos($result->body, $cache_options['signature']) === false)
                    {
                        $result->status            = 0;
                        $result->message           = "Singature {$cache_options['signature']} not found ";
                        $result->invalid_signature = 1;

                        return $result;
                    }
                }

                $name = explode("/", $resource);
                $name = $name[count($name) - 1];

                $tm = microtime(true);
                $this->log("Set cache data for url $resource  for " . ($cache_options['expire'] == 0 ? 'infinite' : $cache_options['expire']));
                // $cached_item= $this->cache->getItem($cache_options['key'])->set($data)->expiresAfter($cache_options['expire'])->addTag($name);
                // $cached_item->set($data)->expiresAfter($cache_options['expire'])->addTag($name); //in seconds, also accepts
                $this->cache->save($this->cache->getItem($cache_options['key'])->set($result->body)->expiresAfter($cache_options['expire'])->addTag($name));
                // $cached_item=null;
                $data = null;
                $this->log("Cache data saved in " . (number_format(microtime(true) - $tm, 2)));
                return $result;

            };
        }
    }

    public function cache($action, $resource, $data = [], $options = [])
    {
        $this->log("Executing $action for $resource");
        $mayCache = $this->mayCache($action, $resource, $options);

        if (!is_callable($mayCache))
        {
            return $mayCache;
        }
        else
        {
            $result = $this->request(
                $action,
                $resource,
                $data,
                $options);

            if ($result->status)
            {
                $result = $mayCache($result);

            }
            $mayCache = null;
            return $result;
        }
    }
}
