<?php

/*
 * Place here any hosts for which we are to be a proxy -
 * e.g. the host on which the J2EE APIs we'll be proxying are running
 * */
@require_once('config.php');

$BLOCKED_HOSTS = array();
if(isset($SETTING_BLOCKED_HOSTS))
    $BLOCKED_HOSTS = $SETTING_BLOCKED_HOSTS; # Override with setting from config.php

/**
 * AJAX Cross Domain (PHP) Proxy 0.8
 *    by Iacovos Constantinou (http://www.iacons.net)
 * 
 * Released under CC-GNU GPL
 */

/**
 * Enables or disables filtering for cross domain requests.
 * Recommended value: true
 */
define( 'CSAJAX_FILTERS', false );

/**
 * If set to true, $valid_requests should hold only domains i.e. a.example.com, b.example.com, usethisdomain.com
 * If set to false, $valid_requests should hold the whole URL ( without the parameters ) i.e. http://example.com/this/is/long/url/
 * Recommended value: false (for security reasons - do not forget that anyone can access your proxy)
 */
define( 'CSAJAX_FILTER_DOMAIN', true );

/**
 * Set debugging to true to receive additional messages - really helpful on development
 */
define( 'CSAJAX_DEBUG', true );

/**
 * A set of valid cross domain requests
 */
$valid_requests = array();

// identify request headers
$request_headers = array();
$setContentType = true;
$isMultiPart = false;
foreach ($_SERVER as $key => $value) {
    if(preg_match('/Content.Type/i', $key)){
        $setContentType = false;
        $content_type = explode(";", $value)[0];
        $isMultiPart = preg_match('/multipart/i', $content_type);
        $request_headers[] = "Content-Type: ".$content_type;
        continue;
    }
    if (substr($key, 0, 5) == 'HTTP_') {
        $headername = str_replace('_', ' ', substr($key, 5));
        $headername = str_replace(' ', '-', ucwords(strtolower($headername)));
        if (!in_array($headername, array('Host', 'X-Proxy-Url'))) {
            $request_headers[] = "$headername: $value";
        }
    }
}

if($setContentType)
    $request_headers[] = "Content-Type: application/json";

// identify request method, url and params
$request_method = $_SERVER['REQUEST_METHOD'];
if ('GET' == $request_method) {
    $request_params = $_GET;
} elseif ('POST' == $request_method) {
    $request_params = $_POST;
    if (empty($request_params)) {
        $data = file_get_contents('php://input');
        if (!empty($data)) {
            $request_params = $data;
        }
    }
} elseif ('PUT' == $request_method || 'DELETE' == $request_method) {
    $request_params = file_get_contents('php://input');
} else {
    $request_params = null;
}

// Get URL from `csurl` in GET or POST data, before falling back to X-Proxy-URL header.
if (isset($_REQUEST['csurl'])) {
    $request_url = urldecode($_REQUEST['csurl']);
} else if (isset($_SERVER['HTTP_X_PROXY_URL'])) {
    $request_url = urldecode($_SERVER['HTTP_X_PROXY_URL']);
} else {
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
    header('Status: 404 Not Found');
    $_SERVER['REDIRECT_STATUS'] = 404;
    exit;
}

// Add http:// if the URL does not start with http:// or https://
if (!preg_match('/^https?:\/\//', $request_url)) {
    $request_url = 'https://' . $request_url;
}

$p_request_url = parse_url($request_url);

// csurl may exist in GET request methods
if (is_array($request_params) && array_key_exists('csurl', $request_params))
    unset($request_params['csurl']);

// ignore requests for proxy :)
if (preg_match('!' . $_SERVER['SCRIPT_NAME'] . '!', $request_url) || empty($request_url) || count($p_request_url) == 1) {
    csajax_debug_message('Invalid request - make sure that csurl variable is not empty');
    exit;
}

// check against blocked requests
if (in_array($p_request_url['host'], $BLOCKED_HOSTS)) {
    csajax_debug_message('Blocked domain - ' . $p_request_url['host'] . ' is included in blocked request domains');
    exit;
}

// append query string for GET requests
if ($request_method == 'GET' && count($request_params) > 0 && (!array_key_exists('query', $p_request_url) || empty($p_request_url['query']))) {
    $request_url .= '?' . http_build_query($request_params);
}

// let the request begin
$ch = curl_init($request_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);   // (re-)send headers
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);     // return response
curl_setopt($ch, CURLOPT_HEADER, true);       // enabled response headers
// add data for POST, PUT or DELETE requests
if ('POST' == $request_method) {
    $post_data = is_array($request_params) ? http_build_query($request_params) : $request_params;

    $has_files = false;
    $file_params = array();

    foreach ($_FILES as $f => $file) {
        if($file['size']){
            $file_params[$f] = '@'. $file['tmp_name'] .";type=". $file['type'];
            $has_files = true;
        }
    }

    if($isMultiPart || $has_files){
        foreach(explode("&",$post_data) as $i => $param) {
            $params = explode("=", $param);
            $xvarname = $params[0];
            if (!empty($xvarname))
                $file_params[$xvarname] = $params[1];
        }
    }

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,  $isMultiPart || $has_files ? $file_params : $post_data);
} elseif ('PUT' == $request_method || 'DELETE' == $request_method) {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request_params);
}

function rewrite_html($content, $base_url, $proxy_url) {
    // Don't try to parse non-HTML content
    if (!preg_match('/text\/html/i', $_SERVER['HTTP_ACCEPT'])) {
        return $content;
    }

    // Replace relative URLs with absolute ones
    $content = preg_replace_callback(
        '/(src|href|action)=["\'](?!http[s]?:\/\/)([^"\']+)["\']/',
        function($matches) use ($base_url) {
            return $matches[1] . '="' . rtrim($base_url, '/') . '/' . ltrim($matches[2], '/') . '"';
        },
        $content
    );

    // Rewrite absolute URLs to go through proxy
    $content = preg_replace_callback(
        '/(src|href|action)=["\'](http[s]?:\/\/[^"\']+)["\']/',
        function($matches) use ($proxy_url) {
            return $matches[1] . '="' . $proxy_url . '?csurl=' . urlencode($matches[2]) . '"';
        },
        $content
    );

    // Add JavaScript to intercept navigation and form submissions
    $inject_script = <<<EOT
    <script>
    (function() {
        // Intercept all link clicks
        document.addEventListener('click', function(e) {
            var target = e.target;
            while(target && target.tagName !== 'A') {
                target = target.parentNode;
            }
            if(target && target.tagName === 'A') {
                var href = target.getAttribute('href');
                if(href && !href.includes('?csurl=')) {
                    e.preventDefault();
                    var baseUrl = '{$proxy_url}?csurl=';
                    if(href.startsWith('http')) {
                        window.location.href = baseUrl + encodeURIComponent(href);
                    } else {
                        var fullUrl = href.startsWith('/') 
                            ? '{$base_url}' + href
                            : '{$base_url}/' + href;
                        window.location.href = baseUrl + encodeURIComponent(fullUrl);
                    }
                }
            }
        });

        // Intercept form submissions
        document.addEventListener('submit', function(e) {
            var form = e.target;
            if(form.tagName === 'FORM') {
                var action = form.getAttribute('action');
                if(action && !action.includes('?csurl=')) {
                    e.preventDefault();
                    var baseUrl = '{$proxy_url}?csurl=';
                    var fullUrl = action.startsWith('http') 
                        ? action 
                        : (action.startsWith('/') 
                            ? '{$base_url}' + action
                            : '{$base_url}/' + action);
                    form.setAttribute('action', baseUrl + encodeURIComponent(fullUrl));
                    form.submit();
                }
            }
        });

        // Intercept History API
        var _pushState = history.pushState;
        var _replaceState = history.replaceState;
        
        history.pushState = function() {
            var url = arguments[2];
            if(url && !url.includes('?csurl=')) {
                var baseUrl = '{$proxy_url}?csurl=';
                var fullUrl = url.startsWith('http') 
                    ? url 
                    : (url.startsWith('/') 
                        ? '{$base_url}' + url
                        : '{$base_url}/' + url);
                arguments[2] = baseUrl + encodeURIComponent(fullUrl);
            }
            return _pushState.apply(this, arguments);
        };

        history.replaceState = function() {
            var url = arguments[2];
            if(url && !url.includes('?csurl=')) {
                var baseUrl = '{$proxy_url}?csurl=';
                var fullUrl = url.startsWith('http') 
                    ? url 
                    : (url.startsWith('/') 
                        ? '{$base_url}' + url
                        : '{$base_url}/' + url);
                arguments[2] = baseUrl + encodeURIComponent(fullUrl);
            }
            return _replaceState.apply(this, arguments);
        };
    })();
    </script>
    EOT;

    // Insert script before </body> tag
    $content = str_replace('</body>', $inject_script . '</body>', $content);
    return $content;
}

// retrieve response (headers and content)
$response = curl_exec($ch);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// split response to header and content
list($response_headers, $response_content) = preg_split('/(\r\n){2}/', $response, 2);

// (re-)send the headers
$response_headers = preg_split('/(\r\n){1}/', $response_headers);
foreach ($response_headers as $key => $response_header) {
    // Rewrite the `Location` header
    if (preg_match('/^Location:/i', $response_header)) {
        list($header, $value) = preg_split('/: /', $response_header, 2);
        $value = trim($value);
        
        // Handle relative URLs in location header
        if (!preg_match('/^https?:\/\//i', $value)) {
            $base_url = $p_request_url['scheme'] . '://' . $p_request_url['host'];
            if (isset($p_request_url['port'])) {
                $base_url .= ':' . $p_request_url['port'];
            }
            $value = rtrim($base_url, '/') . '/' . ltrim($value, '/');
        }
        
        // Rewrite location through proxy
        $response_header = 'Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . 
                          '?csurl=' . urlencode($value);
    }
    
    // Skip transfer-encoding header
    if (!preg_match('/^(Transfer-Encoding):/i', $response_header)) {
        header($response_header, false);
    }
}

// Get base URL for rewriting
$base_url = $p_request_url['scheme'] . '://' . $p_request_url['host'];
if (isset($p_request_url['port'])) {
    $base_url .= ':' . $p_request_url['port'];
}

// Only rewrite HTML content
if (preg_match('/text\/html/i', $content_type)) {
    $proxy_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
                 $_SERVER['HTTP_HOST'] . 
                 strtok($_SERVER['REQUEST_URI'], '?');
    $response_content = rewrite_html($response_content, $base_url, $proxy_url);
}

// Output the content
print($response_content);

function csajax_debug_message($message) {
    if (true == CSAJAX_DEBUG) {
        print $message . PHP_EOL;
    }
}
