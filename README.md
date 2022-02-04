# PHP Proxy

## What is it?

PHP Proxy operates similar to [hosts.cx](https://hosts.cx):

* All incoming requests to the web directory are received and passed into the proxy script.
* The script replaces all occurrences of the current domain with the target domain in headers and the request body.
* The script then forwards the request to the target IP (and optionally port) masquerading it for the target domain.
* After the script receives the response data, it replaces all occurrences of the target domain in headers and the response body with the current domain.
* Lastly, the script sends the response back to the user.

This proxy script enables accessing sites hosted on a server that is different than the server the target domain's DNS points to, making it useful for developing replacement or test sites.

For instance, if a site is hosted at IP `1.2.3.4` but configured to use the domain `example.com`, to visit the site one could add the following to his `hosts` file:

```
1.2.3.4    example.com
```

As an alternative, one could install these three files in an Apache server directory, accessible for instance from `example.mydomain.com`, and use the following configuration of the `index.php` file:

```
<?php

define('DOMAIN',	'example.com');
define('IP',		'1.2.3.4');

include "proxy.php";

$mgr = new ProxyManager(array(
	'oldhost' => DOMAIN,
	'newhost' => $_SERVER['HTTP_HOST'],
	'ip' => IP,
	// 'port' => 80,
));

$mgr->proxy();
$mgr->push();

?>
```

Then, when visiting `example.mydomain.com`, the proxy script would load the server at `1.2.3.4` using `example.com` as the domain. The server would not recognize the requests are originating from `example.mydomain.com` requests, neither would the user's browser visiting `example.mydomain.com` recognize the responses are coming from a server using the domain `example.com`.

## Basic Use

The `index.php` file simplifies use of the `ProxyManager` class:

```
<?php

define('DOMAIN',	'domain.com'); // simply insert your target domain here
define('IP',		'1.2.3.4'); // simply insert the IP the domain is hosted at

include "proxy.php";

$mgr = new ProxyManager(array(
	'oldhost' => DOMAIN,
	'newhost' => $_SERVER['HTTP_HOST'], // here, the current hosts is automatically inferred
	'ip' => IP,
	// 'port' => 80,
));

$mgr->proxy();
$mgr->push();

?>
```

## class ProxyManager

### `ProxyManager::constructor($settings)`

`$settings` is an associative array accepting the following arguments:

* `'oldhost' => string `

*Required*
The domain expected from the target server.

* `'newhost' => string`

*Required*
The domain the client is using to connect to the proxy.

* `'ip' => string`

*Required*
The IP address of the target server.

* `'port' => integer`

*Optional*
The port to use to connect to the target server.

* `'handled_mimes' => array(string, ...)|'all'`

*Optional*
Set to an array of mimetypes. The target domain (`oldhost`) will only be substituted with the current domain (`newhost`) in target server responses with a Content-Type mimetype within this list. Use the string `'all'` instead if substitution should be made in every server response.
To help reduce server request latency, only the following list of mimetypes are used by default, and not `'all'`:

```
text/plain
text/html
application/html
text/xhtml
application/xhtml
application/xhtml+xml
text/xml
application/xml
application/vnd.mozilla.xul+xml
text/csv
text/svg+xml
image/svg+xml
text/css
text/javascript
text/json
application/json
application/ld+json
```

(Note, some of the mimetypes listed above are not latest or accurate, which is intentional in order to be compatible with old or misconfigured servers.)

If your server is configured to use special or custom mimetypes for data that might include the domain, providing a custom list or `'all'` may be necessary.

* `'debug' => boolean`

*Optional*
Set to a truthy value to enable debug mode. When debug mode is on, header and content detail files will be generated for every request and response passing through the server.

### `ProxyManager::proxy()`

The `proxy()` function runs the proxied request. Specifically, it pulls and processes the client's request headers and data, runs current-to-target domain replacements, forwards the request to the target server, then receives the response and runs target-to-current domain replacements on it.

After running this function, the properties `$statusline`, `$originalHeaders`, `$originalContents`, `$headers`, `$contents`, and `$mimetype` are populated on the ProxyManager class instance, and can be used or modified.

### `ProxyManager::push()`

The `push()` function sends the processed response data back to the client.

### `string ProxyManager::$statusline`

After proxying the request, `$statusline` will contain the HTTP status line, e.g. `HTTP/1.1 200 OK`. Changing this before running `push()` will change the status line used in response to the client.

### `array(array('name' => string, 'value' => string), ...) ProxyManager::$originalHeaders`

After proxying the request, `$originalHeaders` will contain an array of the original headers.

Changing this value has no effect when pushing the response to the client.

### `string ProxyManager::$originalContent`

After proxying the request, `$originalContent` will contain the original, unsubstituted content from the target server.

Changing this value has no effect when pushing the response to the client.

### `array(array('name' => string, 'value' => string), ...) ProxyManager::$headers`

After proxying the request, `$headers` will contain the substituted and filtered headers that will be sent back to the client.

Changing this value will change the response headers sent to the client when pushing.

### `string ProxyManager::$contents`

After proxying the request, `$contents` will contain the substituted reponse data that will be sent back to the client.

Changing this value will change the response content sent to the client when pushing.

### `string ProxyManager::$mimetype`

After proxying the request, `$mimetype` will contain the response data's mimetype, pulled from the Content-Type header.

Changing this value has no effect when pushing the response to the client.