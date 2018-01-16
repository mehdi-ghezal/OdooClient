<?php

/**
 * (c) Jacob Steringa <jacobsteringa@gmail.com>
 * (c) Mehdi Ghezal <mehdi.ghezal@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jsg\Odoo;

use Jsg\Odoo\Exception\RuntimeException;
use Zend\XmlRpc\Client as XmlRpcClient;
use Zend\XmlRpc\Request;
use Zend\XmlRpc\Response;
use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerAwareTrait;
use DateInterval;

/**
 * Odoo is an PHP client for the xmlrpc api of Odoo, formerly known as OpenERP.
 * This client should be compatible with version 6 and up of Odoo/OpenERP.
 *
 * This client is inspired on the OpenERP api from simbigo and uses a more or
 * less similar API. Instead of an own XmlRpc class, it relies on the XmlRpc
 * and Xml libraries from ZF.
 *
 * @author  Jacob Steringa <jacobsteringa@gmail.com>
 * @author  Mehdi Ghezal <mehdi.ghezal@gmail.com>
 */
class Odoo
{
    use LoggerAwareTrait;

    /**
     * Host to connect to
     *
     * @var string
     */
    protected $host;

    /**
     * Unique identifier for current user
     *
     * @var integer
     */
    protected $uid;

    /**
     * Context for the current user
     *
     * @var array
     */
    protected $context = [];

    /**
     * Current users username
     *
     * @var string
     */
    protected $user;

    /**
     * Current database
     *
     * @var string
     */
    protected $database;

    /**
     * Password for current user
     *
     * @var string
     */
    protected $password;

    /**
     * XmlRpc Clients
     *
     * @var XmlRpcClient[]
     */
    protected $clients;

    /**
     * XmlRpc Client
     *
     * @var XmlRpcClient
     */
    protected $lastClient;

    /**
     * Optional: A callable return a custom Zend\Http\Client to initialize the XmlRpcClient with
     *
     * @var callable
     */
    protected $httpClientProvider;

    /**
     * Default options for Odoo PHP Clients
     *
     * @var array
     */
    protected $defaultOptions;

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var bool
     */
    protected $cacheActive = false;

    /**
     * @var null|int|\DateInterval
     */
    protected $cacheTTL = null;

    /**
     * Odoo constructor
     *
     * @param string $host The url
     * @param string $database The database to log into
     * @param string $user The username
     * @param string $password Password of the user
     * @param callable $httpClientProvider Optional: A callable return a custom Zend\Http\Client to initialize the XmlRpcClient with
     */
    public function __construct($host, $database, $user, $password, callable $httpClientProvider = null)
    {
        $this->host = $host;
        $this->database = $database;
        $this->user = $user;
        $this->password = $password;
        $this->httpClientProvider = $httpClientProvider;
        $this->clients = [];

        $this->defaultOptions = [
            'offset' => 0,
            'limit' => 100,
            'order' => 'name ASC',
            'context' => [],
            'lazy' => true,
        ];
    }

    /**
     * Define the cache driver
     *
     * @param CacheInterface $cache
     * @return $this
     */
    public function setCache(CacheInterface $cache)
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * Activate the cache for the next API Call
     *
     * @param null|int|DateInterval $ttl Optional. If not specified default value depends of the cache driver.
     * @return $this
     * @throws RuntimeException
     */
    public function withCache($ttl = null)
    {
        if (! $this->cache) {
            throw new RuntimeException('The cache cannot be activated as no cache component has been registered.');
        }

        $this->debug('Activate cache with TTL', [$ttl]);

        $this->cacheActive = true;
        $this->cacheTTL = $ttl;

        return $this;
    }

    /**
     * Configure default options of the Odoo PHP Client
     *
     * @param array $options
     * @return $this
     */
    public function configureDefaultsOptions(array $options)
    {
        $this->debug('Configure defaults options', $options);

        $resolver = new OptionsResolver($this->defaultOptions);
        $this->defaultOptions = $resolver->resolveDefaults($options);

        return $this;
    }

    /**
     * Get version
     *
     * @return array Odoo version
     */
    public function version()
    {
        return $this->getClient('common')->call('version');
    }

    /**
     * @return array
     */
    public function getContext()
    {
        $params = $this->buildParams([
            'res.users',
            'context_get'
        ]);

        $this->debug('Get Context', $params);

        return $this->getClient('object')->call('execute', $params);
    }

    /**
     * Search records
     *
     * @param array $options Array of options
     * @return array Array of record id's
     */
    public function search(array $options)
    {
        $resolver = new OptionsResolver($this->defaultOptions);
        $resolver
            ->registerModelOptions()
            ->registerDomainOptions()
            ->registerOffsetOptions()
            ->registerLimitOptions()
            ->registerOrderOptions()
        ;

        $options = $resolver->resolve($options);

        $params = $this->buildParams([
            $options['model'],
            'search',
            $options['domain'],
            $options['offset'],
            $options['limit'],
            $options['order'],
            array_replace($this->context, $options['context'])
        ]);

        $this->debug(sprintf('Search model %s', $options['model']), $params);

        return $this->_searchOrRead($options['model'], $params);
    }

    /**
     * Search and read records
     *
     * @param array $options Array of options
     * @return array An array of records
     */
    public function searchRead(array $options)
    {
        $resolver = new OptionsResolver($this->defaultOptions);
        $resolver
            ->registerModelOptions()
            ->registerDomainOptions()
            ->registerFieldsOptions()
            ->registerOffsetOptions()
            ->registerLimitOptions()
            ->registerOrderOptions()
        ;

        $options = $resolver->resolve($options);

        $params = $this->buildParams([
            $options['model'],
            'search_read',
            $options['domain'],
            $options['fields'],
            $options['offset'],
            $options['limit'],
            $options['order'],
            array_replace($this->context, $options['context'])
        ]);

        $this->debug(sprintf('SearchRead model %s', $options['model']), $params);

        return $this->_searchOrRead($options['model'], $params);
    }

    /**
     * Search records and return the results count
     *
     * @param array $options Array of options
     * @return int Number of records
     */
    public function searchCount(array $options)
    {
        $resolver = new OptionsResolver($this->defaultOptions);
        $resolver
            ->registerModelOptions()
            ->registerDomainOptions()
        ;

        $options = $resolver->resolve($options);

        $params = $this->buildParams([
            $options['model'],
            'search_count',
            $options['domain'],
            array_replace($this->context, $options['context'])
        ]);

        $this->debug(sprintf('SearchCount model %s', $options['model']), $params);

        return $this->_searchOrRead($options['model'], $params);
    }

    /**
     * Read record(s) by identifier
     *
     * @param array $options Array of options
     * @return array An array of records
     */
    public function read(array $options)
    {
        $resolver = new OptionsResolver($this->defaultOptions);
        $resolver
            ->registerModelOptions()
            ->registerIdsOptions()
            ->registerFieldsOptions()
        ;

        $options = $resolver->resolve($options);

        $params = $this->buildParams([
            $options['model'],
            'read',
            $options['ids'],
            $options['fields'],
            array_replace($this->context, $options['context'])
        ]);

        $this->debug(sprintf('Read model %s', $options['model']), $params);

        return $this->_searchOrRead($options['model'], $params);
    }

    /**
     * Search records with a domain filter and return aggregated results
     *
     * @param array $options Array of options
     * @return array An array of aggregated records
     */
    public function readGroup(array $options)
    {
        $resolver = new OptionsResolver($this->defaultOptions);
        $resolver
            ->registerModelOptions()
            ->registerDomainOptions()
            ->registerFieldsOptions()
            ->registerGroupByOptions()
            ->registerOffsetOptions()
            ->registerLimitOptions()
            ->registerOrderOptions()
            ->registerLazyOptions()
        ;

        $options = $resolver->resolve($options);

        if ($options['lazy']) {
            $options['context']['group_by_no_leaf'] = true;
        }

        $params = $this->buildParams([
            $options['model'],
            'read_group',
            $options['domain'],
            $options['fields'],
            $options['groupBy'],
            $options['offset'],
            $options['limit'],
            array_replace($this->context, $options['context']),
            $options['order'],
            $options['lazy'],
        ]);

        $this->debug(sprintf('ReadGroup model %s', $options['model']), $params);


        return $this->_searchOrRead($options['model'], $params);
    }

    /**
     * Create a record
     *
     * @param array $options Array of options
     * @return integer Created record id
     */
    public function create(array $options)
    {
        $resolver = new OptionsResolver($this->defaultOptions);
        $resolver
            ->registerModelOptions()
            ->registerDataOptions()
        ;

        $options = $resolver->resolve($options);

        $params = $this->buildParams([
            $options['model'],
            'create',
            $options['data'],
            array_replace($this->context, $options['context'])
        ]);

        $this->debug(sprintf('Create model %s', $options['model']), $params);

        $response = $this->getClient('object')->call('execute', $params);

        return $response;
    }

    /**
     * Update record(s)
     *
     * @param array $options Array of options
     * @return array An array of records
     */
    public function write(array $options)
    {
        $resolver = new OptionsResolver($this->defaultOptions);
        $resolver
            ->registerModelOptions()
            ->registerIdsOptions()
            ->registerDataOptions()
        ;

        $options = $resolver->resolve($options);

        $params = $this->buildParams([
            $options['model'],
            'write',
            $options['ids'],
            $options['data'],
            array_replace($this->context, $options['context'])
        ]);

        $this->debug(sprintf('Write model %s', $options['model']), $params);

        $response = $this->getClient('object')->call('execute', $params);

        return $response;
    }

    /**
     * Unlink model(s)
     *
     * @param array $options Array of options
     * @return boolean True if successful
     */
    public function unlink(array $options)
    {
        $resolver = new OptionsResolver($this->defaultOptions);
        $resolver
            ->registerModelOptions()
            ->registerIdsOptions()
        ;

        $options = $resolver->resolve($options);

        $params = $this->buildParams([
            $options['model'],
            'unlink',
            $options['ids'],
            array_replace($this->context, $options['context'])
        ]);

        $this->debug(sprintf('Unlink model %s', $options['model']), $params);

        return $this->getClient('object')->call('execute', $params);
    }

    /**
     * Get a report in PDF Format ; an encoded base64 string is return or false
     *
     * @param array $options Array of options
     * @return string|false
     */
    public function getReport(array $options)
    {
        $resolver = new OptionsResolver($this->defaultOptions);
        $resolver
            ->registerReportOptions()
            ->registerIdsOptions()
        ;

        $options = $resolver->resolve($options);

        $client = $this->getClient('report');
        $params = $this->buildParams([$options['report'], $options['ids']]);
        $response = $client->call('render_report', $params);

        if ($response && isset($response['state']) && $response['state']) {
            return base64_decode($response['result']);
        }

        return false;
    }

    /**
     * Return last XML-RPC Client
     *
     * @return XmlRpcClient
     */
    public function getLastXmlRpcClient()
    {
        return $this->lastClient;
    }

    /**
     * Return last request
     *
     * @return Request
     */
    public function getLastRequest()
    {
        return $this->lastClient ? $this->lastClient->getLastRequest() : null;
    }

    /**
     * Return last response
     *
     * @return Response
     */
    public function getLastResponse()
    {
        return $this->lastClient ? $this->lastClient->getLastResponse() : null;
    }

    /**
     * Set a callable return a custom Zend\Http\Client to initialize the XmlRpcClient with
     *
     * @param callable $httpClientProvider
     * @return $this
     */
    public function setHttpClientProvider(callable $httpClientProvider)
    {
        $this->httpClientProvider = $httpClientProvider;

        return $this;
    }

    /**
     * @param $model
     * @param array $params
     * @return mixed
     */
    protected function _searchOrRead($model, array $params)
    {
        // Cache is ON
        if ($this->cache && $this->cacheActive) {
            $key = $this->calculateCacheKey($params);
            $this->debug(sprintf('Cache lookup for %s with key %s', $model, $key), $params);

            // Cache match, we use cache
            if ($this->cache->has($key)) {
                $this->debug(sprintf('Cache match for model %s with key %s', $model, $key), $params);

                return $this->cache->get($key);
            }

            // Cache didn't match, we request Odoo
            $this->debug(sprintf('Cache not match for model %s with key %s, call Odoo API', $model, $key), $params);
            $results = $this->getClient('object')->call('execute', $params);

            // Save results in cache, and reset cache settings
            $this->cache->set($key, $results, $this->cacheTTL);
            $this->cacheActive = false;
            $this->cacheTTL = null;

            // Return the results
            return $results;
        }

        // Cache is OFF
        $this->debug(sprintf('Cache not use, call Odoo API for model %s', $model), $params);

        return $this->getClient('object')->call('execute', $params);
    }

    /**
     * Build parameters
     *
     * @param array  $params Array of params to append to the basic params
     *
     * @return array
     */
    protected function buildParams(array $params)
    {
        return array_merge([
            $this->database,
            $this->uid(),
            $this->password
        ], $params);
    }

    /**
     * Get XmlRpc Client
     *
     * This method returns an XmlRpc Client for the requested endpoint.
     * If no endpoint is specified or if a client for the requested endpoint is
     * already initialized, the last used client will be returned.
     *
     * @param string $path The api endpoint
     *
     * @return XmlRpcClient
     */
    protected function getClient($path)
    {
        if (! isset($this->clients[$path])) {
            $httpClient = $this->httpClientProvider ? call_user_func($this->httpClientProvider) : null;

            $this->clients[$path] = new XmlRpcClient($this->host . '/' . $path, $httpClient);

            // The introspection done by the Zend XmlRpc client is probably specific
            // to Zend XmlRpc servers. To prevent polution of the Odoo logs with errors
            // resulting from this introspection calls we disable it.
            $this->clients[$path]->setSkipSystemLookup(true);
        }

        $this->lastClient = $this->clients[$path];

        return $this->clients[$path];
    }

    /**
     * Get uid
     *
     * @return int $uid
     */
    protected function uid()
    {
        if ($this->uid === null) {
            $cacheKey = '__authentication';

            // If authentication in cache
            if ($this->cache && $this->cache->has($cacheKey)) {
                $data = $this->cache->get($cacheKey);

                $this->uid = $data['uid'];
                $this->context = $data['context'];

                $this->debug('Authentication get from cache', [$this->uid, $this->context]);

                return $this->uid;
            }

            // Authenticate
            $client = $this->getClient('common');
            $this->uid = $client->call('login', [$this->database, $this->user, $this->password]);
            $this->context = $this->getContext();

            $this->debug('Authentication with Odoo', [$this->uid, $this->context]);

            // If cache active save in cache
            if ($this->cache) {
                $this->cache->set($cacheKey, ['uid' => $this->uid, 'context' => $this->context], new DateInterval('PT30M'));
            }
        }

        return $this->uid;
    }

    /**
     * @param string $message
     * @param array $context
     * @return $this
     */
    protected function debug($message, array $context = [])
    {
        if ($this->logger) {
            $this->logger->debug($message, $context);
        }

        return $this;
    }

    /**
     * @param array $params
     * @return string
     */
    protected function calculateCacheKey(array $params)
    {
        array_multisort($params);

        return md5(json_encode($params));
    }
}
