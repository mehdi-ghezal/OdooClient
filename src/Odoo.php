<?php

/**
 * (c) Jacob Steringa <jacobsteringa@gmail.com>
 * (c) Mehdi Ghezal <mehdi.ghezal@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jsg\Odoo;

use Zend\XmlRpc\Client as XmlRpcClient;
use Zend\XmlRpc\Request;
use Zend\XmlRpc\Response;
use Symfony\Component\OptionsResolver\OptionsResolver;
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
     * @var OptionsResolver
     */
    protected $optionsResolver;

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
        $this->clients = array();

        $this->defaultOptions = array(
            'offset' => 0,
            'limit' => 100,
            'order' => 'name ASC',
            'fields' => array(),
            'context' => array(),
        );
    }

    /**
     * @param CacheInterface $cache
     * @return $this
     */
    public function setCache(CacheInterface $cache)
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * @param null|int|DateInterval $ttl
     * @return $this
     */
    public function withCache($ttl = null)
    {
        if (! $this->cache) {
            $this->debug('The cache cannot be acivated as no cache component has been registered.');

            return $this;
        }

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
        $this->defaultOptions = $this->resolveOptions($options);

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
     * Search models
     *
     * @param string $model Model
     * @param array $domainFilter Array of criteria @see https://www.odoo.com/documentation/10.0/reference/orm.html#domains
     * @param array $options Array of options
     *
     * @return array Array of model id's
     */
    public function search($model, $domainFilter, $options = array())
    {
        $options = $this->resolveOptions($options);

        $params = $this->buildParams(array(
            $model,
            'search',
            $domainFilter,
            $options['offset'],
            $options['limit'],
            $options['order'],
            $options['context'],
        ));

        return $this->_searchOrRead($model, $params);
    }

    /**
     * Search and read models
     *
     * @param string $model Model
     * @param array $domainFilter Array of criteria @see https://www.odoo.com/documentation/10.0/reference/orm.html#domains
     * @param array $options Array of options
     *
     * @return array An array of models
     */
    public function searchRead($model, $domainFilter, $options = array())
    {
        $options = $this->resolveOptions($options);

        $params = $this->buildParams([
            $model,
            'search_read',
            $domainFilter,
            $options['fields'],
            $options['offset'],
            $options['limit'],
            $options['order'],
            $options['context'],
        ]);

        return $this->_searchOrRead($model, $params);
    }

    /**
     * Read model(s)
     *
     * @param string $model Model
     * @param array $ids Array of model id's
     * @param array $options Array of options
     *
     * @return array An array of models
     */
    public function read($model, $ids, $options = [])
    {
        $options = $this->resolveOptions($options);

        $params = $this->buildParams([
            $model,
            'read',
            $ids,
            $options['fields'],
            $options['context'],
        ]);

        return $this->_searchOrRead($model, $params);
    }

    /**
     * Create model
     *
     * @param string $model Model
     * @param array  $data  Array of fields with data (format: ['field' => 'value'])
     *
     * @return integer Created model id
     */
    public function create($model, $data)
    {
        $options = $this->resolveOptions([]);

        $params = $this->buildParams([
            $model,
            'create',
            $data,
            $options['context'],
        ]);

        $this->debug(sprintf('Create model %s', $model), $params);

        $response = $this->getClient('object')->call('execute', $params);

        return $response;
    }

    /**
     * Update model(s)
     *
     * @param string $model  Model
     * @param array  $ids    Array of model id's
     * @param array  $fields A associative array (format: ['field' => 'value'])
     *
     * @return array
     */
    public function write($model, $ids, $fields)
    {
        $options = $this->resolveOptions([]);

        $params = $this->buildParams([
            $model,
            'write',
            $ids,
            $fields,
            $options['context'],
        ]);

        $this->debug(sprintf('Write model %s', $model), $params);

        $response = $this->getClient('object')->call('execute', $params);

        return $response;
    }

    /**
     * Unlink model(s)
     *
     * @param string $model Model
     * @param array  $ids   Array of model id's
     *
     * @return boolean True is successful
     */
    public function unlink($model, $ids)
    {
        $options = $this->resolveOptions([]);

        $params = $this->buildParams([
            $model,
            'unlink',
            $ids,
            $options['context'],
        ]);

        $this->debug(sprintf('Unlink model %s', $model), $params);

        return $this->getClient('object')->call('execute', $params);
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
        if ($this->cacheActive) {
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
            $client = $this->getClient('common');

            $this->uid = $client->call('login', [
                $this->database,
                $this->user,
                $this->password
            ]);
        }

        return $this->uid;
    }

    /**
     * @param array $options
     * @return array
     */
    protected function resolveOptions(array $options)
    {
        if (! $this->optionsResolver) {
            $this->optionsResolver = new OptionsResolver();
            $this->optionsResolver
                ->setDefined('offset')
                ->setDefined('limit')
                ->setDefined('order')
                ->setDefined('fields')
                ->setDefined('context')

                ->setAllowedTypes('offset', 'int')
                ->setAllowedTypes('limit', 'int')
                ->setAllowedTypes('order', 'string')
                ->setAllowedTypes('fields', 'array')
                ->setAllowedTypes('context', 'array')

                ->setAllowedValues('fields', function (array $field) {
                    return is_string($field);
                })

                ->setDefaults($this->defaultOptions)
            ;
        }

        return $this->optionsResolver->resolve($options);
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
