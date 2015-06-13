<?php
namespace Psr7Middlewares;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Middleware to create a digest http authentication
 */
class DigestAuthentication
{
	protected $users;
    protected $realm;
    protected $nonce;

	/**
     * Constructor. Defines de users.
     *
     * @param array  $users [username => password]
     * @param string $realm
     * @param string $nonce
     */
    public function __construct(array $users, $realm = 'Login', $nonce = null)
    {
        $this->users = $users;
        $this->realm = $realm;
        $this->nonce = $nonce ?: uniqid();
    }

	/**
	 * Execute the middleware
	 * 
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface      $response
	 * 
	 * @return ResponseInterface
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next)
	{
		if ($this->login($request)) {
        	return $next($request, $response);
		}

		return $response
			->withStatus(401)
			->withHeader('WWW-Authenticate', 'Digest realm="'.$this->realm.'",qop="auth",nonce="'.$this->nonce.'",opaque="'.md5($this->realm).'"');
	}

	/**
     * Login or check the user credentials
     *
     * @param Request $request
     *
     * @return boolean
     */
    protected function login(ServerRequestInterface $request)
    {
        //Check header
        $authorization = static::parseAuthorizationHeader($request->getHeaderLine('Authorization'));

        if (!$authorization) {
            return false;
        }

        //Check whether user exists
        $password = isset($this->users[$authorization['username']]) ? $this->users[$authorization['username']] : null;

        if (!$password) {
            return false;
        }

        //Check authentication
        return $this->checkAuthentication($authorization, $request->getMethod(), $password);
    }

    /**
     * Validates the user authentication
     *
     * @param array  $authorization
     * @param string $method
     * @param string $password
     *
     * @return boolean
     */
    protected function checkAuthentication(array $authorization, $method, $password)
    {
        $A1 = md5("{$authorization['username']}:{$this->realm}:{$password}");
        $A2 = md5("{$method}:{$authorization['uri']}");

        $validResponse = md5("{$A1}:{$authorization['nonce']}:{$authorization['nc']}:{$authorization['cnonce']}:{$authorization['qop']}:{$A2}");

        return ($authorization['response'] === $validResponse);
    }

	/**
     * Parses the authorization header for a basic authentication.
     *
     * @param string $header
     *
     * @return false|array
     */
    protected static function parseAuthorizationHeader($authorization)
    {
        if (strpos($authorization, 'Digest') !== 0) {
            return false;
        }

        $needed_parts = ['nonce' => 1, 'nc' => 1, 'cnonce' => 1, 'qop' => 1, 'username' => 1, 'uri' => 1, 'response' => 1];
        $data = [];

        preg_match_all('@('.implode('|', array_keys($needed_parts)).')=(?:([\'"])([^\2]+?)\2|([^\s,]+))@', substr($authorization, 7), $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $data[$m[1]] = $m[3] ? $m[3] : $m[4];
            unset($needed_parts[$m[1]]);
        }

        return empty($needed_parts) ? $data : false;
    }
}