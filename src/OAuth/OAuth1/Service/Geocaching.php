<?php

namespace OAuth\OAuth1\Service;

use OAuth\OAuth1\Signature\SignatureInterface;
use OAuth\OAuth1\Token\StdOAuth1Token;
use OAuth\Common\Http\Exception\TokenResponseException;
use OAuth\Common\Http\Uri\Uri;
use OAuth\Common\Consumer\CredentialsInterface;
use OAuth\Common\Http\Uri\UriInterface;
use OAuth\Common\Storage\TokenStorageInterface;
use OAuth\Common\Http\Client\ClientInterface;
use OAuth\Common\Exception\Exception;

class Geocaching extends AbstractService
{

    public $endPoint = null;

    public function __construct(
        CredentialsInterface $credentials,
        ClientInterface $httpClient,
        TokenStorageInterface $storage,
        SignatureInterface $signature,
        UriInterface $baseApiUri = null
    ) {
        parent::__construct($credentials, $httpClient, $storage, $signature, $baseApiUri);
        if (null === $baseApiUri) {
            throw new \Exception('baseApiUri is a required argument.');
        }
    }

    public function getRequestTokenEndpoint()
    {
        return $this->getEndPoint();
    }

    public function getAuthorizationEndpoint()
    {
        return $this->getEndPoint();
    }

    public function getAccessTokenEndpoint()
    {
        return $this->getEndPoint();
    }

    public function getEndPoint()
    {
        return new Uri($this->endPoint);
    }

    public function setEndPoint($endPoint)
    {
        $this->endPoint = $endPoint;
    }

    /**
     * {@inheritdoc}
     */
    protected function getExtraApiHeaders()
    {
        return array('Content-Type' => 'application/json');
    }

    protected function parseRequestTokenResponse($responseBody)
    {
        parse_str($responseBody, $data);
        if (null === $data || !is_array($data)) {
            throw new TokenResponseException('Unable to parse response.');
        } elseif (!isset($data['oauth_callback_confirmed']) || $data['oauth_callback_confirmed'] != 'true') {
            throw new TokenResponseException('Error in retrieving token.');
        }
        return $this->parseAccessTokenResponse($responseBody);
    }

    protected function parseAccessTokenResponse($responseBody)
    {
        parse_str($responseBody, $data);
        if ($data === null || !is_array($data)) {
            throw new TokenResponseException('Unable to parse response.');
        } elseif (isset($data['error'])) {
            throw new TokenResponseException('Error in retrieving token: "' . $data['error'] . '"');
        }

        $token = new StdOAuth1Token();
        $token->setRequestToken($data['oauth_token']);
        $token->setRequestTokenSecret($data['oauth_token_secret']);
        $token->setAccessToken($data['oauth_token']);
        $token->setAccessTokenSecret($data['oauth_token_secret']);
        $token->setEndOfLife(StdOAuth1Token::EOL_NEVER_EXPIRES);
        unset($data['oauth_token'], $data['oauth_token_secret']);
        $token->setExtraParams($data);

        return $token;
    }

    public function request($path, $method = 'GET', $body = null, array $extraHeaders = array())
    {
        $token = $this->storage->retrieveAccessToken($this->service());

        if ($method == 'GET') {
            $parameters = array('AccessToken' => $token->getRequestToken(), 'format' => 'json');
            $query = http_build_query($parameters, '', '&');
            $uri = $this->determineRequestUriFromPath($path . '?' . $query, $this->baseApiUri);
        } elseif ($method == 'POST') {
            $uri  = $this->determineRequestUriFromPath($path . '?format=json', $this->baseApiUri);
            if (!is_array($body)) {
                throw new Exception('body is not an array.');
            }
            $body = array_merge(array('AccessToken' => $token->getRequestToken()), $body);
            $body = json_encode($body);
        }
        $extraHeaders = array_merge($this->getExtraApiHeaders(), $extraHeaders);
        $authorizationHeader = array(
            'Authorization' => $this->buildAuthorizationHeaderForAPIRequest($method, $uri, $token, $body)
        );
        $headers = array_merge($authorizationHeader, $extraHeaders);

        return json_decode($this->httpClient->retrieveResponse($uri, $body, $headers, $method));
    }
}
