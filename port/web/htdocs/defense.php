<?php
function form_csrf()
{
  $token = random_int(PHP_INT_MIN, PHP_INT_MAX);
  SetSessionValue("csrftoken1", $token);
  // error_log("csrf token $token for " . $_SERVER['REQUEST_URI']);
  echo  '  <input type="hidden" name="csrftoken" value="' . $token . '">';
}

session_start();
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // !!! [RB-261322]
    // !!! do not remove csrftoken from session variables.
    // !!! end-user POST form and it will redirect to download file, without refresh page with new CSRF token value in web browser.
    $excepts = array("/events.php", "/export.php", "/subsystem.php", "/phydrvSmartLog.php", "/api/opas.php");
    $ignore_csrf = FALSE;
    foreach ($excepts as $t) {
        if (strpos($_SERVER["REQUEST_URI"], $t) === 0) {
            $ignore_csrf = TRUE;
            break;
        }
    }

    if (!$ignore_csrf) {
        if (!isset($_SESSION["csrftoken1"])) {
            die("no csrf session");
        }
        if (!isset($_POST["csrftoken"])){
            die("missing csrf token");
        }
        if ($_POST["csrftoken"] != $_SESSION["csrftoken1"]) {
            error_log("csrf mismatch " . $_POST["csrftoken"] . " <> " . $_SESSION["csrftoken1"]);
            die("csrf mismatch");
        } else {
            // error_log("csrf match and to be cleared. " . $_POST["csrftoken"]);
            unset($_SESSION["csrftoken1"]);
        }
    } else {
        // error_log("ignore csrf checking for " . $_SERVER["REQUEST_URI"]);
    }
}

/* Cross-Site Request Forgery (CSRF) Prevention */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $strIndex = explode('/', htmlspecialchars($_SERVER['PHP_SELF']));
    if (strcmp($strIndex[1], "index.php") != 0) {
        $strTmp = explode('//', $_SERVER['HTTP_REFERER']);
        $strTmp = $strTmp[1];	
        $strTmp = explode('/', $strTmp);	
        $strTmp = $strTmp[0];
        $strTmp = explode(':', $strTmp);
        $ref = $strTmp[0];
        $srv = "{$_SERVER['SERVER_NAME']}";
        if (strcmp($srv, $ref) != 0) {
            die();
        }
    }
}

if (isset($_SERVER['REQUEST_URI'])) {
    $_urichars_ = $_SERVER['REQUEST_URI'];
    // error_log(print_r($_urichars_, true));
    $harmful = array(
        '!', '%21', '&#33',
        '"', '%22', '&#34',
             '%23', '&#35', // <= '#'
        '$', '%24', '&#36',
             '%25', '&#37', // <= '%'
             '%26', '&#38', // <= '&'
        '\'', '%27', '&#39',
        ')', '%28', '&#40',
        '(', '%29', '&#41',
        '*', '%2A', '&#42', '%2a',
        '+', '%2B', '&#43', '%2b',
        ',', '%2C', '&#44', '%2c',
        '-', '%2D', '&#45', '%2d',
             '%2E', '&#46', '%2e', // <= '.'
             '%2F', '&#47', '%2f', // <= '/'
             '%3A', '&#58', '%3a', // <= ':'
        ';', '%3B', '&#59', '%3b',
        '<', '%3C', '&#60', '%3c',
             '%3D', '&#61', '%3d', // <= '='
        '>', '%3E', '&#62', '%3e',
             '%3F', '&#63', '%3f', // <= '?'
        '@', '%40', '&#64',
        '[', '%5B', '&#91', '%5b',
        '\\', '%5C', '&#92', '%5c',
        ']', '%5D', '&#93', '%5d',
        '^', '%5E', '&#94', '%5e',
        '`', '%60', '&#96',
        '{', '%7B', '&#123', '%7b',
        '|', '%7C', '&#124', '%7c',
        '}', '%7D', '&#125', '%7d',
        '~', '%7E', '&#126', '%7e'
    );
    foreach ($harmful as $h) {
        if (strpos($_urichars_, $h) !== false) {
            // error_log("cross site script injection. illegal [$h]");
            echo '<script>top.window.location.replace(\'https://'.$_SERVER['HTTP_HOST'].'/screen.php\');</script>';
            die();
        }
    }
}

if (!preg_match('/^\/[a-zA-Z0-9\/]+[.]php$/', $_SERVER['PHP_SELF']) &&
    !preg_match('/^\/[a-zA-Z0-9\/]+[.]php[?][^<>\/"]+$/', $_SERVER['PHP_SELF']))
{
    error_log("scripting attacks on URL:" . $_SERVER['PHP_SELF']);
    die();
}

foreach ($_GET as $key => $value) {
    if (is_numeric($value)){
        continue;
    }
    if (!in_array($key, array('act', 'note', 'checkfile', 'file', 'specific'))){
        if (strlen($value)>6){
            error_log("URL parameter is too long: $key");
            die();
        }    
    }
}

if ($_SERVER['PHP_SELF']!=="/index.php" && $_SERVER['PHP_SELF']!=="/indexp.php") {
    if (isset($_COOKIE['forcepw']) && (int)$_COOKIE['forcepw']==1) {
        echo '
        <script>
            top.location.href = "index.php?logout";
        </script>';
        exit();
    }
}

?>