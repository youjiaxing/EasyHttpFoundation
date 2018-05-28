<?php


namespace EasyHttpFoundation;


use Psr\Http\Message\UriInterface;

class Uri implements UriInterface
{
    /**
     * http(s)的绝对地址需要一个host部分. 当uri未提供主机部分时, 使用以下默认主机来组成一个有效的uri
     */
    const DEFAULT_HOST = 'localhost';

    private static $charUnreserved = 'a-zA-Z0-9_\-\.~';
    private static $charSubDelims = '!\$&\'\(\)\*\+,;=';
    private static $defaultPorts = [
        'http'  => 80,
        'https' => 443,
        'ftp' => 21,
        'gopher' => 70,
        'nntp' => 119,
        'news' => 119,
        'telnet' => 23,
        'tn3270' => 23,
        'imap' => 143,
        'pop' => 110,
        'ldap' => 389,
    ];

    /* @var string */
    private $scheme = '';

    /* @var int|null */
    private $port;

    /* @var string */
    private $host = '';

    /* @var string */
    private $userInfo = '';

    /* @var string */
    private $path = '';

    /* @var string */
    private $query = '';

    /* @var string */
    private $fragment = '';

    /**
     * 判断是否使用默认端口
     * @param UriInterface $uri
     * @return bool
     */
    public static function isDefaultPort(UriInterface $uri)
    {
        $scheme = strtolower($uri->getScheme());
        return $uri->getPort() === null
            || (isset(static::$defaultPorts[$scheme]) && static::$defaultPorts[$scheme] === $uri->getPort());
    }

    /**
     * Composes a URI reference string from its various components.
     *
     * Usually this method does not need to be called manually but instead is used indirectly via
     * `Psr\Http\Message\UriInterface::__toString`.
     *
     * PSR-7 UriInterface treats an empty component the same as a missing component as
     * getQuery(), getFragment() etc. always return a string. This explains the slight
     * difference to RFC 3986 Section 5.3.
     *
     * Another adjustment is that the authority separator is added even when the authority is missing/empty
     * for the "file" scheme. This is because PHP stream functions like `file_get_contents` only work with
     * `file:///myfile` but not with `file:/myfile` although they are equivalent according to RFC 3986. But
     * `file:///` is the more common syntax for the file scheme anyway (Chrome for example redirects to
     * that format).
     *
     * @param string $scheme
     * @param string $authority
     * @param string $path
     * @param string $query
     * @param string $fragment
     *
     * @return string
     *
     * @link https://tools.ietf.org/html/rfc3986#section-5.3
     */
    public static function composeComponents($scheme, $authority, $path, $query, $fragment)
    {
        $uri = '';
        if (!empty($scheme)) {
            $uri .= $scheme.':';
        }

        if (!empty($authority) || $scheme === 'file') {
            $uri .= '//'.$authority;
        }

        $uri .= $path;

        if (!empty($query)) {
            $uri .= '?'.$query;
        }

        if (!empty($fragment)) {
            $uri .= '#'.$fragment;
        }

        return $uri;
    }

    /**
     * @param string $uri 待解析的URI
     */
    public function __construct($uri = '')
    {
        if ($uri != '') {
            $parsedUri = parse_url($uri);
            if ($parsedUri === false) {
                throw new \InvalidArgumentException("Invalid URI: $uri");
            }
            $this->applyParts($parsedUri);
        }
    }

    /**
     * Return the string representation as a URI reference.
     *
     * Depending on which components of the URI are present, the resulting
     * string is either a full URI or relative reference according to RFC 3986,
     * Section 4.1. The method concatenates the various components of the URI,
     * using the appropriate delimiters:
     *
     * - If a scheme is present, it MUST be suffixed by ":".
     * - If an authority is present, it MUST be prefixed by "//".
     * - The path can be concatenated without delimiters. But there are two
     *   cases where the path has to be adjusted to make the URI reference
     *   valid as PHP does not allow to throw an exception in __toString():
     *     - If the path is rootless and an authority is present, the path MUST
     *       be prefixed by "/".
     *     - If the path is starting with more than one "/" and no authority is
     *       present, the starting slashes MUST be reduced to one.
     * - If a query is present, it MUST be prefixed by "?".
     * - If a fragment is present, it MUST be prefixed by "#".
     *
     * @see http://tools.ietf.org/html/rfc3986#section-4.1
     * @return string
     */
    public function __toString()
    {
        return self::composeComponents($this->scheme, $this->getAuthority(), $this->path, $this->query, $this->fragment);
    }

    /**
     * Apply parse_url parts to a URI.
     *
     * @param array $uri Array of parse_url parts to apply.
     */
    protected function applyParts(array $uri)
    {
        $this->scheme = isset($uri['scheme'])
            ? $this->filterScheme($uri['scheme'])
            : '';
        $this->userInfo = isset($uri['user']) ? $uri['user'] : '';
        $this->host = isset($uri['host'])
            ? $this->filterHost($uri['host'])
            : '';
        $this->port = isset($uri['port'])
            ? $this->filterPort($uri['port'])
            : null;
        $this->path = isset($uri['path'])
            ? $this->filterPath($uri['path'])
            : '';
        $this->query = isset($uri['query'])
            ? $this->filterQueryAndFragment($uri['query'])
            : '';
        $this->fragment = isset($uri['fragment'])
            ? $this->filterQueryAndFragment($uri['fragment'])
            : '';
        if (isset($uri['pass'])) {
            $this->userInfo .= ':' . $uri['pass'];
        }

        $this->removeDefaultPort();
    }

    /**
     * 简单过滤协议 $scheme
     * @param string $scheme
     * @return string
     */
    private function filterScheme($scheme)
    {
        if (!is_string($scheme)) {
            throw new \InvalidArgumentException('Scheme must be a string');
        }
        return strtolower($scheme);
    }

    private function filterHost($host)
    {
        if (!is_string($host)) {
            throw new \InvalidArgumentException('Host must be a string');
        }
        return strtolower($host);
    }

    private function filterPort($port)
    {
        if (is_null($port)) {
            return null;
        }

        $port = intval($port);
        if ($port < 1 || $port > 0xffff) {
            throw new \InvalidArgumentException(sprintf('Invalid port: %d, Port must between 1 and 65535', $port));
        }

        return $port;
    }

    private function filterPath($path)
    {
        if (!is_string($path)) {
            throw new \InvalidArgumentException('Path must be a string');
        }

        return preg_replace_callback(
            '/(?:[^' . self::$charUnreserved . self::$charSubDelims . '%:@\/]++|%(?![A-Fa-f0-9]{2}))/',
            [$this, 'rawurlencodeMatchZero'],
            $path
        );
    }

    private function filterQueryAndFragment($str)
    {
        if (!is_string($str)) {
            throw new \InvalidArgumentException('Query and fragment must be a string');
        }

        return preg_replace_callback(
            '/(?:[^' . self::$charUnreserved . self::$charSubDelims . '%:@\/\?]++|%(?![A-Fa-f0-9]{2}))/',
            [$this, 'rawurlencodeMatchZero'],
            $str
        );
    }


    private function rawurlencodeMatchZero(array $match)
    {
        return rawurlencode($match[0]);
    }



    /**
     * Retrieve the scheme component of the URI.
     *
     * If no scheme is present, this method MUST return an empty string.
     *
     * The value returned MUST be normalized to lowercase, per RFC 3986
     * Section 3.1.
     *
     * The trailing ":" character is not part of the scheme and MUST NOT be
     * added.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.1
     * @return string The URI scheme.
     */
    public function getScheme()
    {
        return $this->scheme;
    }

    /**
     * Retrieve the authority component of the URI.
     *
     * If no authority information is present, this method MUST return an empty
     * string.
     *
     * The authority syntax of the URI is:
     *
     * <pre>
     * [user-info@]host[:port]
     * </pre>
     *
     * If the port component is not set or is the standard port for the current
     * scheme, it SHOULD NOT be included.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2
     * @return string The URI authority, in "[user-info@]host[:port]" format.
     */
    public function getAuthority()
    {
        $userInfo = $this->getUserInfo();
        $host = $this->getHost();
        $port = $this->getPort();

        $authority = (empty($userInfo) ? '' : $userInfo.'@').$host.(static::isDefaultPort($this) ? '' : ':'.$port);
        return $authority;
    }

    /**
     * Retrieve the user information component of the URI.
     *
     * If no user information is present, this method MUST return an empty
     * string.
     *
     * If a user is present in the URI, this will return that value;
     * additionally, if the password is also present, it will be appended to the
     * user value, with a colon (":") separating the values.
     *
     * The trailing "@" character is not part of the user information and MUST
     * NOT be added.
     *
     * @return string The URI user information, in "username[:password]" format.
     */
    public function getUserInfo()
    {
        return $this->userInfo;
    }

    /**
     * Retrieve the host component of the URI.
     *
     * If no host is present, this method MUST return an empty string.
     *
     * The value returned MUST be normalized to lowercase, per RFC 3986
     * Section 3.2.2.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-3.2.2
     * @return string The URI host.
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Retrieve the port component of the URI.
     *
     * If a port is present, and it is non-standard for the current scheme,
     * this method MUST return it as an integer. If the port is the standard port
     * used with the current scheme, this method SHOULD return null.
     *
     * If no port is present, and no scheme is present, this method MUST return
     * a null value.
     *
     * If no port is present, but a scheme is present, this method MAY return
     * the standard port for that scheme, but SHOULD return null.
     *
     * @return null|int The URI port.
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * Retrieve the path component of the URI.
     *
     * The path can either be empty or absolute (starting with a slash) or
     * rootless (not starting with a slash). Implementations MUST support all
     * three syntaxes.
     *
     * Normally, the empty path "" and absolute path "/" are considered equal as
     * defined in RFC 7230 Section 2.7.3. But this method MUST NOT automatically
     * do this normalization because in contexts with a trimmed base path, e.g.
     * the front controller, this difference becomes significant. It's the task
     * of the user to handle both "" and "/".
     *
     * The value returned MUST be percent-encoded, but MUST NOT double-encode
     * any characters. To determine what characters to encode, please refer to
     * RFC 3986, Sections 2 and 3.3.
     *
     * As an example, if the value should include a slash ("/") not intended as
     * delimiter between path segments, that value MUST be passed in encoded
     * form (e.g., "%2F") to the instance.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-2
     * @see https://tools.ietf.org/html/rfc3986#section-3.3
     * @return string The URI path.
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Retrieve the query string of the URI.
     *
     * If no query string is present, this method MUST return an empty string.
     *
     * The leading "?" character is not part of the query and MUST NOT be
     * added.
     *
     * The value returned MUST be percent-encoded, but MUST NOT double-encode
     * any characters. To determine what characters to encode, please refer to
     * RFC 3986, Sections 2 and 3.4.
     *
     * As an example, if a value in a key/value pair of the query string should
     * include an ampersand ("&") not intended as a delimiter between values,
     * that value MUST be passed in encoded form (e.g., "%26") to the instance.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-2
     * @see https://tools.ietf.org/html/rfc3986#section-3.4
     * @return string The URI query string.
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Retrieve the fragment component of the URI.
     *
     * If no fragment is present, this method MUST return an empty string.
     *
     * The leading "#" character is not part of the fragment and MUST NOT be
     * added.
     *
     * The value returned MUST be percent-encoded, but MUST NOT double-encode
     * any characters. To determine what characters to encode, please refer to
     * RFC 3986, Sections 2 and 3.5.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-2
     * @see https://tools.ietf.org/html/rfc3986#section-3.5
     * @return string The URI fragment.
     */
    public function getFragment()
    {
        return $this->fragment;
    }

    /**
     * Return an instance with the specified scheme.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified scheme.
     *
     * Implementations MUST support the schemes "http" and "https" case
     * insensitively, and MAY accommodate other schemes if required.
     *
     * An empty scheme is equivalent to removing the scheme.
     *
     * @param string $scheme The scheme to use with the new instance.
     * @return static A new instance with the specified scheme.
     * @throws \InvalidArgumentException for invalid or unsupported schemes.
     */
    public function withScheme($scheme)
    {
        $scheme = $this->filterScheme($scheme);
        if ($scheme === $this->scheme) {
            return $this;
        }

        $new = clone $this;
        $new->scheme = $scheme;
        $new->removeDefaultPort();
        $new->validateState();

        return $new;
    }


    /**
     * 若当前指定了协议对应的默认端口, 则移除该端口
     */
    private function removeDefaultPort()
    {
        if ($this->port !== null && self::isDefaultPort($this)) {
            $this->port = null;
        }
    }

    /**
     * 验证当前Uri配置是否有效
     *
     * 配置默认host 以及验证 path 开头是否合法
     *
     * 涉及到 scheme, authority(userInfo, host, port), path修改的均需调用该方法进行验证.
     */
    protected function validateState()
    {
        // 配置http(s)默认host
        if (empty($this->host) && ($this->scheme === 'http' || $this->scheme === 'https')) {
            $this->host = static::DEFAULT_HOST;
        }

        if ($this->getAuthority() === '') {
            if (0 === strpos($this->path, '//')) {
                throw new \InvalidArgumentException('The path of a URI without an authority must not start with two slashes "//"');
            }
            if ($this->scheme === '' && false !== strpos(explode('/', $this->path, 2)[0], ':')) {
                throw new \InvalidArgumentException('A relative URI must not have a path beginning with a segment containing a colon');
            }
        } elseif (isset($this->path[0]) && $this->path[0] !== '/') {
            @trigger_error(
                'The path of a URI with an authority must start with a slash "/" or be empty. Automagically fixing the URI ' .
                'by adding a leading slash to the path is deprecated since version 1.4 and will throw an exception instead.',
                E_USER_DEPRECATED
            );
            $this->path = '/'. $this->path;
            //throw new \InvalidArgumentException('The path of a URI with an authority must start with a slash "/" or be empty');
        }
    }

    /**
     * Return an instance with the specified user information.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified user information.
     *
     * Password is optional, but the user information MUST include the
     * user; an empty string for the user is equivalent to removing user
     * information.
     *
     * @param string $user The user name to use for authority.
     * @param null|string $password The password associated with $user.
     * @return static A new instance with the specified user information.
     */
    public function withUserInfo($user, $password = null)
    {
        $userInfo = $user;
        if (!empty($password)) {
            $userInfo .= ':'.$password;
        }

        if ($this->userInfo === $userInfo) {
            return $this;
        }

        $new = clone $this;
        $new->userInfo = $userInfo;
        $this->validateState();
        return $new;
    }

    /**
     * Return an instance with the specified host.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified host.
     *
     * An empty host value is equivalent to removing the host.
     *
     * @param string $host The hostname to use with the new instance.
     * @return static A new instance with the specified host.
     * @throws \InvalidArgumentException for invalid hostnames.
     */
    public function withHost($host)
    {
        $host = $this->filterHost($host);

        if ($this->host === $host) {
            return $this;
        }

        $new = clone $this;
        $new->host = $host;
        $this->validateState();
        return $new;
    }

    /**
     * Return an instance with the specified port.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified port.
     *
     * Implementations MUST raise an exception for ports outside the
     * established TCP and UDP port ranges.
     *
     * A null value provided for the port is equivalent to removing the port
     * information.
     *
     * @param null|int $port The port to use with the new instance; a null value
     *     removes the port information.
     * @return static A new instance with the specified port.
     * @throws \InvalidArgumentException for invalid ports.
     */
    public function withPort($port)
    {
        $port = $this->filterPort($port);
        if ($port === $this->port) {
            return $this;
        }

        $new = clone $this;
        $new->port = $port;
        $new->removeDefaultPort();
        $new->validateState();
        return $new;
    }

    /**
     * Return an instance with the specified path.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified path.
     *
     * The path can either be empty or absolute (starting with a slash) or
     * rootless (not starting with a slash). Implementations MUST support all
     * three syntaxes.
     *
     * If the path is intended to be domain-relative rather than path relative then
     * it must begin with a slash ("/"). Paths not starting with a slash ("/")
     * are assumed to be relative to some base path known to the application or
     * consumer.
     *
     * Users can provide both encoded and decoded path characters.
     * Implementations ensure the correct encoding as outlined in getPath().
     *
     * @param string $path The path to use with the new instance.
     * @return static A new instance with the specified path.
     * @throws \InvalidArgumentException for invalid paths.
     */
    public function withPath($path)
    {
        $path = $this->filterPath($path);

        if ($path === $this->path) {
            return $this;
        }

        $new = clone $this;
        $new->path = $path;
        $this->validateState();

        return $new;
    }

    /**
     * Return an instance with the specified query string.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified query string.
     *
     * Users can provide both encoded and decoded query characters.
     * Implementations ensure the correct encoding as outlined in getQuery().
     *
     * An empty query string value is equivalent to removing the query string.
     *
     * @param string $query The query string to use with the new instance.
     * @return static A new instance with the specified query string.
     * @throws \InvalidArgumentException for invalid query strings.
     */
    public function withQuery($query)
    {
        $query = $this->filterQueryAndFragment($query);

        if ($this->query === $query) {
            return $this;
        }

        $new = clone $this;
        $new->query = $query;
        return $new;
    }

    /**
     * Return an instance with the specified URI fragment.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified URI fragment.
     *
     * Users can provide both encoded and decoded fragment characters.
     * Implementations ensure the correct encoding as outlined in getFragment().
     *
     * An empty fragment value is equivalent to removing the fragment.
     *
     * @param string $fragment The fragment to use with the new instance.
     * @return static A new instance with the specified fragment.
     */
    public function withFragment($fragment)
    {
        $fragment = $this->filterQueryAndFragment($fragment);

        if ($this->fragment === $fragment) {
            return $this;
        }

        $new = clone $this;
        $new->fragment = $fragment;
        return $new;
    }
}