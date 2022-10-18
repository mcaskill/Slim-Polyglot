# Slim 3 Polyglot

Resolves the response's current language based on the requested URI,
the client's preferred language, and the available languages.

Alters the `ResponseInterface` to assign the `Content-Language` header.

Uses [willdurand/Negotiation](https://github.com/willdurand/Negotiation)
to detect and negotiate the client language.

## Install

Via Composer:

``` bash
composer require mcaskill/slim-polyglot
```

Requires Slim 3.

## Usage

```php
<?php

use Slim\App;
use McAskill\Slim\Polyglot\Polyglot;

$app = new App();

// Fetch DI Container
$container = $app->getContainer();

// Register Middleware
$app->add( new Polyglot([ 'en', 'fr', 'es' ]) );

// Example route with ETag header
$app->get('/foo', function ($request, $response) {
	$language = $response->getHeader('Content-Language');

	// Handle response!
});

$app->run();
```

The Polyglot middleware can also accept callbacks that are executed
after a language is chosen.

```php
<?php

use Slim\App;
use McAskill\Slim\Polyglot\Polyglot;

$app = new App();

// Fetch DI Container
$container = $app->getContainer();

// Register Middleware
$app->add(
	new Polyglot([
		'languages' => [ 'en', 'fr', 'es' ],
		'fallbackLanguage' => 'fr',
		// Hooks to call after language is resolved
		'callbacks' => [
			function ($language) use ($container) {
				$container['environment']['language.current'] = $language;
			},
			[ 'MySuperApp', 'set_language' ]
		]
	])
);

// Example route with ETag header
$app->get('/foo', function ($request, $response) {
	$language = $this->environment['language.current'];

	// Handle response!
});

$app->run();
```

## Testing

_TBD_

## Notes

The language code may be formatted as ISO 639-1 alpha-2 (en),
ISO 639-3 alpha-3 (msa), or ISO 639-1 alpha-2 combined with
an ISO 3166-1 alpha-2 localization (zh-tw).

```json
[ "en", "fr-CA" ]
```

When giving a list of languages to the Polyglot middleware, the first language
is used as the fallback language. When switching to a language that is not in
the list of supported languages, the first language is used instead.

Definitions:

- "language-fallback" — Default language; determined as the first
  among your supported languages.
- "language-preferred" — Preferred language(s); determined as the client's
  localization preferences.
- "language-current" — Current language; determined as the localization
  to use based on a cross-reference of the application's supported languages
  and the client's priority of preferred languages.

## License

The MIT License (MIT). Please see the [License File](LICENSE)
for more information.
