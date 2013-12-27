<?php
/**
 * OAuth 2.0 Authorization Server
 *
 * @package     php-loep/oauth2-server
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) 2013 PHP League of Extraordinary Packages
 * @license     http://mit-license.org/
 * @link        http://github.com/php-loep/oauth2-server
 */

namespace League\OAuth2\Server;

use League\OAuth2\Server\Grant\GrantTypeInterface;
use League\OAuth2\Server\Exception\ClientException;
use League\OAuth2\Server\Exception\ServerException;
use League\OAuth2\Server\Exception\InvalidGrantTypeException;
use League\OAuth2\Server\Storage\ClientInterface;
use League\OAuth2\Server\Storage\AccessTokenInterface;
use League\OAuth2\Server\Storage\AuthCodeInterface;
use League\OAuth2\Server\Storage\RefreshTokenInterface;
use League\OAuth2\Server\Storage\SessionInterface;
use League\OAuth2\Server\Storage\ScopeInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * OAuth 2.0 authorization server class
 */
class AuthorizationServer
{
    /**
     * The delimeter between scopes specified in the scope query string parameter
     *
     * The OAuth 2 specification states it should be a space but most use a comma
     *
     * @var string
     */
    protected $scopeDelimeter = ' ';

    /**
     * The TTL (time to live) of an access token in seconds (default: 3600)
     *
     * @var integer
     */
    protected $accessTokenTTL = 3600;

    /**
     * The registered grant response types
     *
     * @var array
     */
    protected $responseTypes = [];

    /**
     * The client, scope and session storage classes
     *
     * @var array
     */
    protected $storage = [];

    /**
     * The registered grant types
     *
     * @var array
     */
    protected $grantTypes = [];

    /**
     * Require the "scope" parameter to be in checkAuthorizeParams()
     *
     * @var boolean
     */
    protected $requireScopeParam = false;

    /**
     * Default scope(s) to be used if none is provided
     *
     * @var string|array
     */
    protected $defaultScope = null;

    /**
     * Require the "state" parameter to be in checkAuthorizeParams()
     *
     * @var boolean
     */
    protected $requireStateParam = false;

    /**
     * The request object
     *
     * @var Request
     */
    protected $request = null;

    /**
     * Exception error messages
     *
     * @var array
     */
    protected static $exceptionMessages = [
        'invalid_request'           =>  'The request is missing a required parameter, includes an invalid parameter value, includes a parameter more than once, or is otherwise malformed. Check the "%s" parameter.',
        'unauthorized_client'       =>  'The client is not authorized to request an access token using this method.',
        'access_denied'             =>  'The resource owner or authorization server denied the request.',
        'unsupported_response_type' =>  'The authorization server does not support obtaining an access token using this method.',
        'invalid_scope'             =>  'The requested scope is invalid, unknown, or malformed. Check the "%s" scope.',
        'server_error'              =>  'The authorization server encountered an unexpected condition which prevented it from fulfilling the request.',
        'temporarily_unavailable'   =>  'The authorization server is currently unable to handle the request due to a temporary overloading or maintenance of the server.',
        'unsupported_grant_type'    =>  'The authorization grant type "%s" is not supported by the authorization server',
        'invalid_client'            =>  'Client authentication failed',
        'invalid_grant'             =>  'The provided authorization grant is invalid, expired, revoked, does not match the redirection URI used in the authorization request, or was issued to another client. Check the "%s" parameter.',
        'invalid_credentials'       =>  'The user credentials were incorrect.',
        'invalid_refresh'           =>  'The refresh token is invalid.',
    ];

    /**
     * Exception error HTTP status codes
     *
     * RFC 6749, section 4.1.2.1.:
     * No 503 status code for 'temporarily_unavailable', because
     * "a 503 Service Unavailable HTTP status code cannot be
     * returned to the client via an HTTP redirect"
     *
     * @var array
     */
    protected static $exceptionHttpStatusCodes = [
        'invalid_request'           =>  400,
        'unauthorized_client'       =>  400,
        'access_denied'             =>  401,
        'unsupported_response_type' =>  400,
        'invalid_scope'             =>  400,
        'server_error'              =>  500,
        'temporarily_unavailable'   =>  400,
        'unsupported_grant_type'    =>  501,
        'invalid_client'            =>  401,
        'invalid_grant'             =>  400,
        'invalid_credentials'       =>  400,
        'invalid_refresh'           =>  400,
    ];

    /**
     * Get all headers that have to be sent with the error response
     *
     * @param  string $error The error message key
     * @return array         Array with header values
     */
    public static function getExceptionHttpHeaders($error)
    {
        $headers = [];
        switch (self::$exceptionHttpStatusCodes[$error]) {
            case 401:
                $headers[] = 'HTTP/1.1 401 Unauthorized';
                break;
            case 500:
                $headers[] = 'HTTP/1.1 500 Internal Server Error';
                break;
            case 501:
                $headers[] = 'HTTP/1.1 501 Not Implemented';
                break;
            case 400:
            default:
                $headers[] = 'HTTP/1.1 400 Bad Request';
        }

        // Add "WWW-Authenticate" header
        //
        // RFC 6749, section 5.2.:
        // "If the client attempted to authenticate via the 'Authorization'
        // request header field, the authorization server MUST
        // respond with an HTTP 401 (Unauthorized) status code and
        // include the "WWW-Authenticate" response header field
        // matching the authentication scheme used by the client.
        if ($error === 'invalid_client') {
            $authScheme = null;
            $request = new Request();
            if ($request->server('PHP_AUTH_USER') !== null) {
                $authScheme = 'Basic';
            } else {
                $authHeader = $request->header('Authorization');
                if ($authHeader !== null) {
                    if (strpos($authHeader, 'Bearer') === 0) {
                        $authScheme = 'Bearer';
                    } elseif (strpos($authHeader, 'Basic') === 0) {
                        $authScheme = 'Basic';
                    }
                }
            }
            if ($authScheme !== null) {
                $headers[] = 'WWW-Authenticate: '.$authScheme.' realm=""';
            }
        }

        return $headers;
    }

    /**
     * Get an exception message
     * @param  string $error The error message key
     * @return string        The error message
     */
    public static function getExceptionMessage($error = '')
    {
        return self::$exceptionMessages[$error];
    }

    /**
     * Set the client storage
     * @param ClientInterface $client
     * @return self
     */
    public function setClientStorage(ClientInterface $client)
    {
        $this->storages['client'] = $client;
        return $this;
    }

    /**
     * Set the session storage
     * @param SessionInterface $session
     * @return self
     */
    public function setSessionStorage(SessionInterface $session)
    {
        $this->storages['session'] = $session;
        return $this;
    }

    /**
     * Set the access token storage
     * @param AccessTokenInterface $accessToken
     * @return self
     */
    public function setAccessTokenStorage(AccessTokenInterface $accessToken)
    {
        $this->storages['access_token'] = $accessToken;
        return $this;
    }

    /**
     * Set the refresh token storage
     * @param RefreshTokenInteface $refreshToken
     * @return self
     */
    public function setRefreshTokenStorage(RefreshTokenInterface $refreshToken)
    {
        $this->storages['refresh_token'] = $refreshToken;
        return $this;
    }

    /**
     * Set the auth code storage
     * @param AuthCodeInterface $authCode
     * @return self
     */
    public function setAuthCodeStorage(AuthCodeInterface $authCode)
    {
        $this->storages['auth_code'] = $authCode;
        return $this;
    }

    /**
     * Set the scope storage
     * @param ScopeInterface $scope
     * @return self
     */
    public function setScopeStorage(ScopeInterface $scope)
    {
        $this->storages['scope'] = $scope;
        return $this;
    }

    /**
     * Enable support for a grant
     * @param GrantTypeInterface $grantType  A grant class which conforms to Interface/GrantTypeInterface
     * @param null|string        $identifier An identifier for the grant (autodetected if not passed)
     * @return self
     */
    public function addGrantType(GrantTypeInterface $grantType, $identifier = null)
    {
        if (is_null($identifier)) {
            $identifier = $grantType->getIdentifier();
        }

        // Inject server into grant
        $grantType->setAuthorizationServer($this);

        $this->grantTypes[$identifier] = $grantType;

        if ( ! is_null($grantType->getResponseType())) {
            $this->responseTypes[] = $grantType->getResponseType();
        }

        return $this;
    }

    /**
     * Check if a grant type has been enabled
     * @param  string  $identifier The grant type identifier
     * @return boolean Returns "true" if enabled, "false" if not
     */
    public function hasGrantType($identifier)
    {
        return (array_key_exists($identifier, $this->grantTypes));
    }

    /**
     * Returns response types
     * @return array
     */
    public function getResponseTypes()
    {
        return $this->responseTypes;
    }

    /**
     * Require the "scope" paremter in checkAuthoriseParams()
     * @param  boolean $require
     * @return self
     */
    public function requireScopeParam($require = true)
    {
        $this->requireScopeParam = $require;
        return $this;
    }

    /**
     * Is the scope parameter required?
     * @return bool
     */
    public function scopeParamRequired()
    {
        return $this->requireScopeParam;
    }

    /**
     * Default scope to be used if none is provided and requireScopeParam is false
     * @param self
     */
    public function setDefaultScope($default = null)
    {
        $this->defaultScope = $default;
        return $this;
    }

    /**
     * Default scope to be used if none is provided and requireScopeParam is false
     * @return string|null
     */
    public function getDefaultScope()
    {
        return $this->defaultScope;
    }

    /**
     * Require the "state" paremter in checkAuthoriseParams()
     * @param  boolean $require
     * @return void
     */
    public function stateParamRequired()
    {
        return $this->requireStateParam;
    }

    /**
     * Require the "state" paremter in checkAuthoriseParams()
     * @param  boolean $require
     * @return void
     */
    public function requireStateParam($require = true)
    {
        $this->requireStateParam = $require;
        return $this;
    }

    /**
     * Get the scope delimeter
     * @return string The scope delimiter (default: ",")
     */
    public function getScopeDelimiter()
    {
        return $this->scopeDelimeter;
    }

    /**
     * Set the scope delimiter
     * @param string $scopeDelimeter
     */
    public function setScopeDelimiter($scopeDelimeter = ' ')
    {
        $this->scopeDelimeter = $scopeDelimeter;
        return $this;
    }

    /**
     * Get the TTL for an access token
     * @return int The TTL
     */
    public function getAccessTokenTTL()
    {
        return $this->accessTokenTTL;
    }

    /**
     * Set the TTL for an access token
     * @param int $accessTokenTTL The new TTL
     */
    public function setAccessTokenTTL($accessTokenTTL = 3600)
    {
        $this->accessTokenTTL = $accessTokenTTL;
        return $this;
    }

    /**
     * Sets the Request Object
     * @param Request The Request Object
     * @return self
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Gets the Request object. It will create one from the globals if one is not set.
     *
     * @return Request
     */
    public function getRequest()
    {
        if ($this->request === null) {
            $this->request = Request::createFromGlobals();
        }

        return $this->request;
    }

    /**
     * Return a storage class
     * @param  string $obj The class required
     * @return Storage\ClientInterface|Storage\ScopeInterface|Storage\SessionInterface
     */
    public function getStorage($obj)
    {
        if (!isset($this->storages[$obj])) {
            throw new ServerException('The `'.$obj.'` storage interface has not been registered with the authorization
                server');
        }
        return $this->storages[$obj];
    }

    /**
     * Issue an access token
     * @param  array $inputParams Optional array of parsed $_POST keys
     * @return array Authorise request parameters
     */
    public function issueAccessToken($inputParams = [])
    {
        $grantType = $this->getRequest()->request->get('grant_type');
        if (is_null($grantType)) {
            throw new ClientException(sprintf(self::$exceptionMessages['invalid_request'], 'grant_type'), 0);
        }

        // Ensure grant type is one that is recognised and is enabled
        if ( ! in_array($grantType, array_keys($this->grantTypes))) {
            throw new ClientException(sprintf(self::$exceptionMessages['unsupported_grant_type'], $grantType), 7);
        }

        // Complete the flow
        return $this->getGrantType($grantType)->completeFlow($inputParams);
    }

    /**
     * Return a grant type class
     * @param  string $grantType The grant type identifer
     * @return Grant\AuthCode|Grant\ClientCredentials|Grant\Implict|Grant\Password|Grant\RefreshToken
     */
    public function getGrantType($grantType)
    {
        if (isset($this->grantTypes[$grantType])) {
            return $this->grantTypes[$grantType];
        }

        throw new InvalidGrantTypeException(sprintf(self::$exceptionMessages['unsupported_grant_type'], $grantType), 9);
    }
}
