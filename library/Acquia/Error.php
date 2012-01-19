<?php

/**
 * @file
 * Error handling functionality.
 */

/**
 * Error handling functionality.
 */
class Acquia_Error {

  /**
   * Prints and returns a generic error page.
   */
  static public function page() {
    header($_SERVER['SERVER_PROTOCOL'] .' 500 Service unavailable');
    echo <<<EOF
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
<head>
<title>Error</title>
</head>
<body>
The website encountered an unexpected error. Please try again later.
</body>
</html>
EOF;
    exit;
  }
}
