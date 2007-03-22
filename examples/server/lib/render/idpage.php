<?php

require_once "lib/session.php";
require_once "lib/render.php";

define('idpage_pat',
       '<html>
<head>
  <link rel="openid2.provider openid.server" href="%s"/>
</head>
<body>
  This is the identity page for %s.
</body>
</html>');

define('login_needed_pat',
       'You must be logged in as %s to approve this request.');

function idpage_render($identity)
{
    $esc_identity = htmlspecialchars($identity, ENT_QUOTES);
    $body = sprintf(idpage_pat, buildURL(), $esc_identity);
    return array(array(), $body);
}

?>