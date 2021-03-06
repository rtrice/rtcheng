<?php

// Note:
//     Please try to use the https url to bypass keyword filtering.
//     Otherwise, dont forgot set [paas]passowrd in proxy.ini
// Contributor:
//     Phus Lu        <phus.lu@gmail.com>

$__version__  = '3.1.1';
$__password__ = 'tiao.651017';
$__timeout__  = 20;
$__content_type__ = 'image/gif';


function message_html($title, $banner, $detail) {
    $error = <<<ERROR_STRING
<html><head>
<meta http-equiv="content-type" content="text/html;charset=utf-8">
<title>${title}</title>
<style><!--
body {font-family: arial,sans-serif}
div.nav {margin-top: 1ex}
div.nav A {font-size: 10pt; font-family: arial,sans-serif}
span.nav {font-size: 10pt; font-family: arial,sans-serif; font-weight: bold}
div.nav A,span.big {font-size: 12pt; color: #0000cc}
div.nav A {font-size: 10pt; color: black}
A.l:link {color: #6f6f6f}
A.u:link {color: green}
//--></style>

</head>
<body text=#000000 bgcolor=#ffffff>
<table border=0 cellpadding=2 cellspacing=0 width=100%>
<tr><td bgcolor=#3366cc><font face=arial,sans-serif color=#ffffff><b>Error</b></td></tr>
<tr><td>&nbsp;</td></tr></table>
<blockquote>
<H1>${banner}</H1>
${detail}

<p>
</blockquote>
<table width=100% cellpadding=0 cellspacing=0><tr><td bgcolor=#3366cc><img alt="" width=1 height=4></td></tr></table>
</body></html>
ERROR_STRING;
    return $error;
}


function decode_request($data) {
    list($headers_length) = array_values(unpack('n', substr($data, 0, 2)));
    $headers_data = gzinflate(substr($data, 2, $headers_length));
    $body = substr($data, 2+intval($headers_length));

    $method  = '';
    $url     = '';
    $headers = array();
    $kwargs  = array();

    foreach (explode("\n", $headers_data) as $kv) {
        $pair = explode(':', $kv, 2);
        $key  = $pair[0];
        $value = trim($pair[1]);
        if ($key == 'G-Method') {
            $method = $value;
        } else if ($key == 'G-Url') {
            $url = $value;
        } else if (substr($key, 0, 2) == 'G-') {
            $kwargs[strtolower(substr($key, 2))] = $value;
        } else if ($key) {
            $key = join('-', array_map('ucfirst', explode('-', $key)));
            $headers[$key] = $value;
        }
    }
    if (isset($headers['Content-Encoding'])) {
        if ($headers['Content-Encoding'] == 'deflate') {
            $body = gzinflate($body);
            $headers['Content-Length'] = strval(strlen($body));
            unset($headers['Content-Encoding']);
        }
    }
    return array($method, $url, $headers, $kwargs, $body);
}

function header_function($ch, $header) {
    if (!isset($GLOBALS['__header__'])) {
        $GLOBALS['__header__'] = '';
        header('Content-Type: ' . $__content_type__);
    }
    if (substr($header, 0, 17) != 'Transfer-Encoding') {
        $GLOBALS['__header__'] .= $header;
    }
    return strlen($header);
}

function echo_content($content) {
    $__password__ = $GLOBALS['__password__'];
    if ($__password__) {
        echo $content ^ str_repeat($__password__[0], strlen($content));
    } else {
        echo $content;
    }
}

function write_function($ch, $content) {
    if (isset($GLOBALS['__header__'])) {
        echo_content($GLOBALS['__header__']);
        unset($GLOBALS['__header__']);
    }

    echo_content($content);
    return strlen($content);
}

function post()
{
    list($method, $url, $headers, $kwargs, $body) = @decode_request(@file_get_contents('php://input'));

    $password = $GLOBALS['__password__'];
    if ($password) {
        if (!isset($kwargs['password']) || $password != $kwargs['password']) {
            header("HTTP/1.0 403 Forbidden");
            print(message_html('403 Forbidden', 'Wrong Password'));
            exit(-1);
        }
    }

    if ($body) {
        $headers['Content-Length'] = strval(strlen($body));
    }
    $headers['Connection'] = 'close';

    $timeout = $GLOBALS['__timeout__'];

    $curl_opt = array();

    $curl_opt[CURLOPT_RETURNTRANSFER] = true;
    $curl_opt[CURLOPT_BINARYTRANSFER] = true;

    $curl_opt[CURLOPT_HEADER]         = false;
    $curl_opt[CURLOPT_HEADERFUNCTION] = 'header_function';
    $curl_opt[CURLOPT_WRITEFUNCTION]  = 'write_function';

    $curl_opt[CURLOPT_FAILONERROR]    = true;
    $curl_opt[CURLOPT_FOLLOWLOCATION] = false;

    $curl_opt[CURLOPT_CONNECTTIMEOUT] = $timeout;
    $curl_opt[CURLOPT_TIMEOUT]        = $timeout;

    $curl_opt[CURLOPT_SSL_VERIFYPEER] = false;
    $curl_opt[CURLOPT_SSL_VERIFYHOST] = false;

    switch (strtoupper($method)) {
        case 'HEAD':
            $curl_opt[CURLOPT_NOBODY] = true;
            break;
        case 'GET':
            break;
        case 'POST':
            $curl_opt[CURLOPT_POST] = true;
            $curl_opt[CURLOPT_POSTFIELDS] = $body;
            break;
        case 'PUT':
        case 'DELETE':
            $curl_opt[CURLOPT_CUSTOMREQUEST] = $method;
            $curl_opt[CURLOPT_POSTFIELDS] = $body;
            break;
        default:
            print(message_html('502 Urlfetch Error', 'Invalid Method: ' . $method,  $url));
            exit(-1);
    }

    $header_array = array();
    foreach ($headers as $key => $value) {
        if ($key) {
            $header_array[] = join('-', array_map('ucfirst', explode('-', $key))).': '.$value;
        }
    }
    $curl_opt[CURLOPT_HTTPHEADER] = $header_array;

    $ch = curl_init($url);
    curl_setopt_array($ch, $curl_opt);
    $ret = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($status == 204 || $status == 304) {
        echo_content("HTTP/1.1 $status\r\n\r\n");
    } else if ($errno) {
        $content = "HTTP/1.0 502\r\n\r\n" . message_html('502 Urlfetch Error', "PHP Urlfetch Error curl($errno)",  curl_error($ch));
        echo_content($content);
    }
    curl_close($ch);
}

function get() {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];
    $domain = preg_replace('/.*\\.(.+\\..+)$/', '$1', $host);
    if ($host && $host != $domain && $host != 'www'.$domain) {
        header('Location: http://www.' . $domain);
    } else {
        header('Location: https://www.google.com');
    }
}

function main() {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        post();
    } else {
        get();
    }
}

main();
