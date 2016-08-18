<?php

namespace PCextreme\Cloudstack;

use InvalidArgumentException;
use PCextreme\Cloudstack\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class Client extends AbstractClient
{
    /**
     * @var array
     */
    protected $apiList;

    /**
     * @var string
     */
    private $urlApi;

    /**
     * @var string
     */
    private $urlClient;

    /**
     * @var string
     */
    private $urlConsole;

    /**
     * @var string
     */
    protected $apiKey;

    /**
     * @var string
     */
    protected $secretKey;

    /**
     * @var string
     */
    private $responseError = 'errortext';

    /**
     * @var string
     */
    private $responseCode = 'errorcode';

    /**
     * Constructs a new Cloudstack client instance.
     *
     * @param  array  $options
     *     An array of options to set on this client. Options include
     *     'apiList', 'urlApi', 'urlClient', 'urlConsole', 'apiKey',
     *     'secretKey', 'responseError' and 'responseCode'.
     * @param  array  $collaborators
     *     An array of collaborators that may be used to override
     *     this provider's default behavior. Collaborators include
     *     `requestFactory` and `httpClient`.
     * @return void
     */
    public function __construct(array $options = [], array $collaborators = [])
    {
        $this->assertRequiredOptions($options);

        $possible   = $this->getConfigurableOptions();
        $configured = array_intersect_key($options, array_flip($possible));

        foreach ($configured as $key => $value) {
            $this->$key = $value;
        }

        // Remove all options that are only used locally
        $options = array_diff_key($options, $configured);

        parent::__construct($options, $collaborators);
    }

    /**
     * Returns all options that can be configured.
     *
     * @return array
     */
    protected function getConfigurableOptions()
    {
        return array_merge($this->getRequiredOptions(), [
            'apiList',
            'urlClient',
            'urlConsole',
            'responseError',
            'responseCode',
        ]);
    }

    /**
     * Returns all options that are required.
     *
     * @return array
     */
    protected function getRequiredOptions()
    {
        return [
            'urlApi',
            'apiKey',
            'secretKey',
        ];
    }

    /**
     * Verifies that all required options have been passed.
     *
     * @param  array  $options
     * @return void
     * @throws InvalidArgumentException
     */
    private function assertRequiredOptions(array $options)
    {
        $missing = array_diff_key(array_flip($this->getRequiredOptions()), $options);

        if (! empty($missing)) {
            throw new InvalidArgumentException(
                'Required options not defined: ' . implode(', ', array_keys($missing))
            );
        }
    }

    public function command($command, array $options = [])
    {
        $this->assertRequiredCommandOptions($command, $options);

        $method  = $this->getCommandMethod($command);
        $url     = $this->getCommandUrl($command, $options);
        $request = $this->getRequest($method, $url, $options);

        return $this->getResponse($request);
    }

    /**
     * Verifies that all required options have been passed.
     *
     * @param  array $options
     * @return void
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    private function assertRequiredCommandOptions($command, array $options = [])
    {
        $apiList = $this->getApiList();

        if (! array_key_exists($command, $apiList)) {
            throw new RuntimeException(
                "Call to unsupported API command [{$command}], this call is not present in the API list."
            );
        }

        foreach ($apiList[$command]['params'] as $key => $value) {
            if (! array_key_exists($key, $options) && (bool) $value['required']) {
                throw new InvalidArgumentException(
                    "Missing argument [{$key}] for command [{$command}] must be of type [{$value['type']}]."
                );
            }
        }
    }

    /**
     * Returns command method based on the command.
     *
     * @param  string  $command
     * @return array
     */
    public function getCommandMethod($command)
    {
        if (in_array($command, ['login', 'deployVirtualMachine'])) {
            return self::METHOD_POST;
        }

        return self::METHOD_GET;
    }

    /**
     * Builds the command URL's query string.
     *
     * @param  array  $params
     * @return string
     */
    public function getCommandQuery(array $params)
    {
        return $this->signCommandParameters($params);
    }

    /**
     * Builds the authorization URL.
     *
     * @param  string  $command
     * @param  array   $options
     * @return string
     */
    public function getCommandUrl($command, array $options = [])
    {
        $base   = $this->getBaseApiUrl();
        $params = $this->getCommandParameters($command, $options);
        $query  = $this->getCommandQuery($params);

        return $this->appendQuery($base, $query);
    }

    /**
     * Returns command parameters based on provided options.
     *
     * @param  string  $command
     * @param  array   $options
     * @return array
     */
    protected function getCommandParameters($command, array $options)
    {
        return array_merge($options, [
            'command'  => $command,
            'response' => 'json',
            'apikey'   => $this->getApiKey(),
        ]);
    }

    /**
     * Signs the command parameters.
     *
     * @param  array  $params
     * @return array
     */
    protected function signCommandParameters(array $params = [])
    {
        ksort($params);

        $query = $this->buildQueryString($params);

        $signature = rawurlencode(base64_encode(hash_hmac(
            'SHA1',
            strtolower($query),
            $this->getSecretKey(),
            true
        )));

        // To prevent the signature from being escaped we simply append
        // the signature to the previously build query.
        return $query . '&signature=' . $signature;
    }

    /**
     * Get Cloudstack Client API list.
     *
     * Tries to load the API list from the cache directory when
     * the 'apiList' on the class is empty.
     *
     * @return array
     * @throws RuntimeException
     */
    public function getApiList()
    {
        if (is_null($this->apiList)) {
            $path = __DIR__ . '/../cache/api_list.php';

            if (! file_exists($path)) {
                throw new RuntimeException(
                    "Cloudstack Client API list not found. This file needs to be generated before using the client."
                );
            }

            $this->apiList = require $path;
        }

        return $this->apiList;
    }

    /**
     * Set Cloudstack Client API list.
     *
     * @param  array  $list
     * @return void
     */
    public function setApiList(array $apiList)
    {
        $this->apiList = $apiList;
    }

    /**
     * Returns the base URL for API requests.
     *
     * @return string
     */
    public function getBaseApiUrl()
    {
        return $this->urlApi;
    }

    /**
     * Returns the API key.
     *
     * @return string
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * Returns the secret key.
     *
     * @return string
     */
    public function getSecretKey()
    {
        return $this->secretKey;
    }

    /**
     * Appends a query string to a URL.
     *
     * @param  string  $url
     * @param  string  $query
     * @return string
     */
    protected function appendQuery($url, $query)
    {
        $query = trim($query, '?&');

        if ($query) {
            return $url . '?' . $query;
        }

        return $url;
    }

    /**
     * Build a query string from an array.
     *
     * @param  array  $params
     * @return string
     */
    protected function buildQueryString(array $params)
    {
        return http_build_query($params, false, '&', PHP_QUERY_RFC3986);
    }

    /**
     * Checks a provider response for errors.
     *
     * @param  ResponseInterface  $response
     * @param  array|string       $data
     * @return void
     * @throws \PCextreme\Cloudstack\Exceptions\ClientException
     */
    protected function checkResponse(ResponseInterface $response, $data)
    {
        if (isset(reset($data)[$this->responseError])) {
            $error = reset($data)[$this->responseError];
            $code  = $this->responseCode ? reset($data)[$this->responseCode] : 0;

            throw new ClientException($error, $code, $data);
        }
    }

    /**
     * Handle dynamic method calls into the method.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        array_unshift($parameters, $method);

        return call_user_func_array(array($this, 'command'), $parameters);
    }
}