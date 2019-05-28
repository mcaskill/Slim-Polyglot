<?php

namespace McAskill\Slim\Polyglot;

use RuntimeException;
use InvalidArgumentException;

use Negotiation\LanguageNegotiator as Negotiator;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Polyglot Middleware
 *
 * Resolves the application's current language based on the client's
 * preferred language and the application's available languages.
 *
 * The language code may be formatted as ISO 639-1 alpha-2 (en),
 * ISO 639-3 alpha-3 (msa), or ISO 639-1 alpha-2 combined with an
 * ISO 3166-1 alpha-2 localization (zh-tw).
 *
 * ```json
 * [ "en", "fr" ]
 * ```
 *
 * When giving a list of languages to the Polyglot middleware,
 * the first language is used as the fallback language. When switching
 * to a language that is not in the list of supported languages,
 * the first language is used instead.
 *
 * Definitions:
 * • "language-fallback" — Default language; determined as the first
 *   among your supported languages.
 * • "language-preferred" — Preferred language(s); determined as the client's
 *   localization preferences.
 * • "language-current" — Current language; determined as the localization
 *   to use based on a cross-reference of the application's supported languages
 *   and the client's priority of preferred languages.
 * • "language" — Current language.
 *
 * @link https://github.com/oscarotero/psr7-middlewares/blob/master/src/Middleware/LanguageNegotiator.php
 */
class Polyglot
{
    /**
     * @const Matches ISO 639-1, ISO 639-2, and ISO 639-3
     */
    const ISO639   = '(?<language>[a-z]{2,3})(?![-_])';

    /**
     * @const Matches ISO 639-1 (alpha-2)
     */
    const ISO639_1 = '(?<language>[a-z]{2})(?![-_])';

    /**
     * @const Matches ISO 639-2/B and ISO 639-2/T (alpha-3)
     */
    const ISO639_2 = '(?<language>[a-z]{3})(?![-_])';

    /**
     * @const Matches ISO 639-3 (alpha-3)
     */
    const ISO639_3 = '(?<language>[a-z]{3})(?![-_])';

    /**
     * @const Matches ISO 639-6 (alpha-4)
     * @deprecated Withdrawn in 2014.
     */
    const ISO639_6 = '(?<language>[a-z]{4})(?![-_])';

    /**
     * @const Matches ISO 3166-1 (alpha-2)
     */
    const ISO3166_1 = '(?<language>[A-Z]{2})(?![-_])';

    /**
     * @const Matches UN M.49 (digit-3)
     */
    const UNM49 = '(?<language>[0-9]{3})(?![-_])';

    /**
     * @const Matches an IETF language tag composed of two subtags:
     *        • 2-letter language (ISO 639-1)
     *        • 2-letter country (ISO 3166-1)
     */
    # PHP 5.6 -- const RFC1766 = self::ISO639_1 . '(?:[-_]' . self::ISO3166_1 . ')?';
    const RFC1766 = '(?<language>[a-z]{2})(?:[-_](?<country>[A-Z]{2}))?';

    /**
     * @const Matches an IETF language tag composed of two subtags:
     *        • 3-letter language (ISO 639-2)
     *        • 2-letter country (ISO 3166-1)
     */
    # PHP 5.6 -- const RFC3066 = self::ISO639_2 . '(?:[-_]' . self::ISO3166_1 . ')?';
    const RFC3066 = '(?<language>[a-z]{3})(?:[-_](?<country>[A-Z]{2}))?';

    /**
     * @const Matches an IETF language tag composed of two subtags:
     *        • 2 or 3-letter language (ISO 639-1, ISO 639-2, and ISO 639-3)
     *        • 2-letter or 3-digit country subtag (ISO 3166-1 or UN M.49)
     */
    # PHP 5.6 -- const RFC5646 = self::ISO639 . '(?:[-_](?:' . self::ISO3166_1 . '|' . self::UNM49 . '))?';
    const RFC5646 = '(?<language>[a-z]{2,3})(?:[-_](?<country>[A-Z]{2}|[0-9]{3}))?';

    /**
     * Regular expression used for matching culture code.
     *
     * @var RegEx|string
     */
    protected $regex = self::ISO639;

    /**
     * Whether a language must always be present in a URI
     *
     * @var boolean
     */
    protected $languageRequiredInUri = false;

    /**
     * Whether routes are defined with a language (e.g., `{lang:en|fr}/foo`)
     *
     * @var boolean
     */
    protected $languageIncludedInRoutes = false;

    /**
     * Whether to save and reuse the result of language calculations between different runs.
     *
     * @var boolean
     */
    protected $saveInSession = true;

    /**
     * Query string keys to look for when resolving the current language (e.g., `?lang=fr`)
     *
     * @var string[]
     */
    protected $queryKeys = [];

    /**
     * Third-party call stack
     *
     * The stack is called after a language has been resolved.
     *
     * @var callable[]
     */
    protected $callbacks = [];

    /**
     * Supported languages
     *
     * Should be either an array or an object. If an object is used, then it must
     * implement ArrayAccess and should implement Countable and Iterator (or
     * IteratorAggregate) if storage limit enforcement is required.
     *
     * @var array|ArrayAccess
     */
    protected $languages;

    /**
     * Default language
     *
     * Determined as the first language among the supported languages (@see self::$languages).
     *
     * @var string
     */
    protected $fallbackLanguage;

    /**
     * Create new Polyglot
     *
     * Since each parameter requires a distinct type, parameters can be omitted if uneeded.
     *
     * @param array $options {
     *     Optional. To configure the middleware.
     *
     *     @var array                    $languages                Defines the supported languages.
     *     @var null|string              $fallbackLanguage         Defines the fallback language; must exist in $languages.
     *                                                               If undefined, the first item from $languages is used.
     *     @var null|callable|callable[] $callbacks                Defines one or more functions or methods
     *                                                               to call when the language has been established.
     *     @var null|boolean             $languageRequiredInUri    Defines whether a language is always present in the URI.
     *     @var null|string|string[]     $queryStringKeys          Defines the keys to look for among the request's query parameters.
     *     @var null|boolean             $languageIncludedInRoutes Defines whether routes include language.
     *     @var boolean                  $saveInSession            Defines whether to save and reuse the result of language calculations between different runs.
     * }
     */
    public function __construct(array $options = [])
    {
        $default_args = [
            'languages'                => [],
            'fallbackLanguage'         => null,
            'callbacks'                => null,
            'regexPattern'             => null,
            'queryStringKeys'          => [],
            'languageRequiredInUri'    => null,
            'languageIncludedInRoutes' => null,
            'saveInSession'            => true,
        ];

        $args = array_merge($default_args, $options);

        if ( isset($args['callback']) ) {
            $args['callbacks'] = $args['callback'];
        }

        if ( isset($args['callable']) ) {
            $args['callbacks'] = $args['callable'];
        }

        if ( isset($args['callables']) ) {
            $args['callbacks'] = $args['callables'];
        }

        extract($args, EXTR_SKIP);

        if (is_array($languages)) {
            $this->setSupportedLanguages($languages);
        }

        if (isset($fallbackLanguage)) {
            $this->setFallbackLanguage($fallbackLanguage);
        }

        if (is_callable($callbacks)) {
            $this->addCallback($callbacks);
        } elseif (is_array($callbacks)) {
            $this->setCallbacks($callbacks);
        }

        if (isset($regexPattern)) {
            $this->setRegEx($regexPattern);
        }

        if (is_string($queryStringKeys)) {
            $this->addQueryKey($queryStringKeys);
        } elseif (is_array($queryStringKeys)) {
            $this->setQueryKeys($queryStringKeys);
        }

        if (isset($languageRequiredInUri)) {
            $this->isLanguageRequiredInUri($languageRequiredInUri);
        }

        if (isset($languageIncludedInRoutes)) {
            $this->isLanguageIncludedInRoutes($languageIncludedInRoutes);
        }

        if (is_bool($saveInSession)) {
            $this->saveInSession = $saveInSession;
        }
    }

    /**
     * Invoke middleware
     *
     * If no language is detected from the route, it's retrieved
     * from the client's Accept-Language header.
     *
     * @param  RequestInterface  $request  PSR7 request object
     * @param  ResponseInterface $response PSR7 response object
     * @param  callable          $next     Next callable middleware
     *
     * @return ResponseInterface PSR7 response object
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next)
    {
        /** @var callable[]|null A call stack to send the resolved language to. */
        $callbacks = $this->getCallbacks();

        /** @var string Setup fallback language. */
        $fallback = $this->getFallbackLanguage();

        /** @var string Assign the fallback language to the request for future middleware. */
        $request = $request->withAttribute('language-fallback', $fallback);

        /** @var boolean Whether a redirection is requested. This determines if the current request
         *               will move on the next middleware or will be interrupted.
         */
        $redirected = false;

        /** @var string For manipulation later on in this method. */
        $uri = $request->getUri()->withUserInfo('');

        /** @var string Start language resolution with the current request's query parameters. */
        $language = $this->getFromQuery($request);

        /** @var string Start language resolution with the current request URI. */
        if ( empty($language) ) {
            $language = $this->getFromPath($request);
        }

        if ( empty($language) ) {
            /** @var string Retrieve a language from the client's headers. */
            $language = $this->getUserLanguage($request);
            $request  = $request->withAttribute('language-preferred', $language);

            if ( empty($language) ) {
                $language = $fallback;
            }

            /** If the language is required, make sure the URI has it. */
            if ( $this->isLanguageRequiredInUri() ) {
                $path       = $this->prependLanguage($uri->getPath(), $language);
                $response   = $response->withRedirect($uri->withPath($path), 303);
                $redirected = true;
            }
            else {
                $response = $response->withHeader(
                    'Link',
                    sprintf(
                        '<%s>; rel="canonical"',
                        $uri->withPath(
                            $this->prependLanguage(
                                $uri->getPath(),
                                $language
                            )
                        )
                    )
                );
            }
        }
        elseif ( ! $this->isSupported($language) ) {

            $path       = $this->replaceLanguage($uri->getPath(), $language, $fallback);
            $request    = $request->withUri( $uri->withPath( $this->stripLanguage( $uri->getPath(), $language)));

            $language = $this->getUserLanguage($request);
            $request  = $request->withAttribute('language-preferred', $language);

            $language = $fallback;
            $request  = $request->withAttribute('language-path', $fallback);

            $response   = $response->withRedirect($uri->withPath($path), 303);
            $redirected = true;
        }

        if ( ! $redirected && ! $this->isLanguageIncludedInRoutes() ) {

            $path = $this->stripLanguage( $uri->getPath(), $language);

            //Empty path redirect to path '/'
            if (empty($path)) {
                $response   = $response->withRedirect($uri->withPath($this->prependLanguage('/', $language)), 303);
            }

            $request = $request->withUri($uri->withPath( $path, $language));
        }

        /** Assign the language to the request, response, client session, and third-party service. */
        $request  = $request->withAttribute('language-current', $language);
        $response = $response->withHeader('Content-Language', $language);
        $this->setUserLanguage($language);

        if ( count($callbacks) ) {
            foreach ($callbacks as $callable) {
                call_user_func($callable, $language);
            }
        }

        //Next Middleware
        $response = $next($request, $response);

        //After
        /** If the language is required, make sure the URI has it on redirect. */
        if ( $this->isLanguageRequiredInUri() && $response->isRedirect() ) {

            $response_uri = (object)parse_url($response->getHeaderLine('Location'));

            //Only same host apply language
            if (!$response_uri->host || $response_uri->host === $uri->getHost()) {
                $path = $uri->getBasePath() != '' ? str_replace($uri->getBasePath() . '/', '', $response_uri->path) : $response_uri->path;
                $path = $this->replaceLanguage($path, $language, $language);
                $response   = $response->withRedirect($uri->withPath($path), 303);
            }
        }

        return $response;
    }

    /**
     * Strip the language from the URI
     *
     * @param  string  $path
     * @param  string  $language Optional. Defaults to the current language.
     *
     * @return string
     */
    public function stripLanguage($path, $language = null)
    {
        $strip = '/' . ( isset($language) ? $language : $this->getLanguage() );

        if ( strlen($strip) > 1 && strpos($path, $strip) === 0 ) {
            $path = substr($path, strlen($strip));
        }

        return $path;
    }

    /**
     * Prepend the language to the URI
     *
     * @param  string  $path
     * @param  string  $language    Optional. The language tho prepend. Defaults to the current language.
     *
     * @return string
     */
    public function prependLanguage($path, $language = null)
    {
        $prepend = ( isset($language) ? $language : $this->getLanguage() );

        if ( strlen($prepend) > 1 ) {
            return $prepend . (strpos($path, '/') === 0 ? $path : '/' . $path);
        }

        return $path;
    }

    /**
     * Replace the language in the URI
     *
     * @param  string  $path
     * @param  string  $language    The language being searched for.
     * @param  string  $replacement Optional. The language that replaces $lang. Defaults to the current language.
     *
     * @return string
     */
    public function replaceLanguage($path, $language, $replacement = null)
    {
        $path = $this->stripLanguage($path, $language);
        $path = $this->prependLanguage($path, $replacement);

        return $path;
    }

    /**
     * Retrieve the language using the Accept-Language header.
     *
     * @param  RequestInterface  $request  PSR7 request object
     *
     * @return null|string
     */
    protected function getFromHeader(ServerRequestInterface $request)
    {
        $accept = $request->getHeaderLine('Accept-Language');

        if ( empty($accept) || empty($this->languages) ) {
            return;
        }

        $language = (new Negotiator())->getBest($accept, $this->languages);

        if ( $language ) {
            return $language->getValue();
        }
    }

    /**
     * Retrieve the language from the requested URI.
     *
     * @param  RequestInterface  $request  PSR7 request object
     *
     * @return null|string
     */
    protected function getFromPath(ServerRequestInterface $request)
    {
        $uri   = $request->getUri();
        $regex = '~^\/?' . $this->getRegEx() . '\b~';
        $path = rtrim( $uri->getPath(), '/\\' ) . '/';

        if ( preg_match($regex, $path, $matches) ) {
            if (isset($matches['language'])) {
                return $matches['language'];
            } else {
                throw new RuntimeException('The regular expression pattern is missing a named subpattern "language".');
            }
        }
    }

    /**
     * Retrieve the language from the requested URI's query string.
     *
     * @param  RequestInterface  $request  PSR7 request object
     *
     * @return null|string
     */
    protected function getFromQuery(ServerRequestInterface $request)
    {
        $params = array_intersect_key($request->getQueryParams(), array_flip($this->getQueryKeys()));
        $regex  = '~^\/?' . $this->getRegEx() . '\b~';

        foreach ($params as $key => $value) {
            if ( preg_match($regex, $value, $matches) ) {
                if (isset($matches['language'])) {
                    return $matches['language'];
                } else {
                    throw new RuntimeException('The regular expression pattern is missing a named subpattern "language".');
                }
            }
        }
    }

    /**
     * Retrieve the supported languages
     *
     * @return array
     */
    public function getSupportedLanguages()
    {
        return $this->languages;
    }

    /**
     * Set the supported languages
     *
     * @param array $languages
     *
     * @return self
     *
     * @throws RuntimeException if the variable isn't an array
     */
    public function setSupportedLanguages(array $languages)
    {
        $this->languages = $languages;
        $this->fallbackLanguage = reset($languages);

        return $this;
    }

    /**
     * Retrieve the language
     *
     * @param  null|RequestInterface  $request  PSR7 request object
     *
     * @return string|null
     *
     * @todo   Add support for self::getFromPath($request).
     */
    public function getLanguage(ServerRequestInterface $request = null)
    {
        return ( $this->getUserLanguage($request) ?: $this->getFallbackLanguage() );
    }

    /**
     * Retrieve the fallback language
     *
     * @return string|null
     */
    public function getFallbackLanguage()
    {
        return $this->fallbackLanguage;
    }

    /**
     * Set the fallback language
     *
     * Must be one of the supported languages.
     *
     * @param string $language The fallback language.
     *
     * @return self
     *
     * @throws RuntimeException if the variable isn't a supported language
     */
    public function setFallbackLanguage($language)
    {
        if ( $this->isSupported($language) ) {
            $this->fallbackLanguage = $language;
        }
        else {
            throw new RuntimeException('Variable must be one of the supported languages.');
        }

        return $this;
    }

    /**
     * Retrieve the user's preferred language
     *
     * Looks in the user session and the Accept-Language header
     * if a request is provided. If the language is not part of
     * the supported set, returns the fallback language.
     *
     * @param  RequestInterface  $request  PSR7 request object
     *
     * @return string|null
     */
    public function getUserLanguage(ServerRequestInterface $request = null)
    {
        if (
            $this->saveInSession &&
            isset($_SESSION['language']) &&
            $this->isSupported($_SESSION['language'])
        ) {
            return $_SESSION['language'];
        }

        if ( empty($language) && $request instanceof ServerRequestInterface ) {
            return $this->getFromHeader($request);
        }

        return null;
    }

    /**
     * Set the current language in the user session
     *
     * Sessions must be enabled.
     *
     * Passed value must be one of the supported languages.
     * If the language is not part of the list of languages,
     * reverts to the fallback language.
     *
     * @param string $anguage The preferred language.
     *
     * @return self
     */
    public function setUserLanguage($language)
    {
        if ($this->saveInSession) {
            $_SESSION['language'] = $this->sanitizeLanguage($language);
        }

        return $this;
    }

    /**
     * Retrieve the callback to be called.
     *
     * @return callable[] The callable to be called.
     */
    public function getCallbacks()
    {
        return $this->callbacks;
    }

    /**
     * Set the callbacks used to assign the current language to.
     *
     * @param callable[] $callbacks The callable to be called.
     *
     * @return self
     */
    public function setCallbacks(array $callables)
    {
        $this->callbacks = [];

        foreach ($callables as $callable) {
            if (is_callable($callable)) {
                $this->addCallback($callable);
            }
        }

        return $this;
    }

    /**
     * Add callback to assign the current language to.
     *
     * @param callable $callable The callable to be called.
     *
     * @return self
     */
    public function addCallback(callable $callable)
    {
        $this->callbacks[] = $callable;

        return $this;
    }

    /**
     * Retrieve the query string keys.
     *
     * @return string[] The query string keys.
     */
    public function getQueryKeys()
    {
        return $this->queryKeys;
    }

    /**
     * Set the query string keys to look for when resolving the current language.
     *
     * @param string[] $keys The query string keys.
     *
     * @return self
     */
    public function setQueryKeys(array $keys)
    {
        $this->queryKeys = [];

        foreach ($keys as $key) {
            if (is_string($key)) {
                $this->addQueryKey($key);
            }
        }

        return $this;
    }

    /**
     * Add a query string key to look for when resolving the current language.
     *
     * @param string $key A query string key.
     *
     * @return self
     */
    public function addQueryKey($key)
    {
        if ( ! is_string($key) ) {
            throw new InvalidArgumentException('Query string key must be a string.');
        }

        $this->queryKeys[] = $key;

        return $this;
    }

    /**
     * Retrieve the culture code syntax
     *
     * @return string
     */
    public function getRegEx()
    {
        return $this->regex;
    }

    /**
     * Set the culture code syntax
     *
     * @param string $regex The regular expression
     *
     * @return self
     *
     * @throws RuntimeException if the variable isn't a string
     */
    public function setRegEx($regex)
    {
        if ( is_string($regex) ) {
            $this->regex = $regex;
        }
        else {
            throw new InvalidArgumentException('Variable must be a string.');
        }

        return $this;
    }

    /**
     * Retrieve the fallback language if the variable isn't a supported language.
     *
     * @param string $anguage
     *
     * @return string
     *
     * @throws RuntimeException if there are no available languages
     */
    public function sanitizeLanguage($language)
    {
        if ( 0 === count($this->languages) ) {
            throw new RuntimeException('Polyglot features no supported languages.');
        }

        if ( ! $this->isSupported($language) ) {
            $language = $this->getFallbackLanguage();
        }

        return $language;
    }

    /**
     * Determine if languages are required in a URI.
     *
     * @param bool|null $state
     *
     * @return bool
     */
    public function isLanguageRequiredInUri($state = null)
    {
        if (isset($state)) {
            $this->languageRequiredInUri = (bool) $state;
        }

        return $this->languageRequiredInUri;
    }

    /**
     * Determine if languages are included in a route.
     *
     * @param bool|null $state
     *
     * @return bool
     */
    public function isLanguageIncludedInRoutes($state = null)
    {
        if (isset($state)) {
            $this->languageIncludedInRoutes = (bool) $state;
        }

        return $this->languageIncludedInRoutes;
    }

    /**
     * Determine if a language is supported.
     *
     * @param string $anguage
     *
     * @return bool
     */
    public function isSupported($language)
    {
        return in_array($language, $this->languages);
    }
}
