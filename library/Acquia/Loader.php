<?php

/**
 * @file
 * Class that autoloads other Acquia classes.
 */

/**
 * Autoloader for Acquia classes.
 */
class Acquia_Loader {

  /**
   * Registers the Acquia autoloader.
   */
  static public function register() {
    spl_autoload_register(array(new self, 'load'));
  }

  /**
   * Autoloads a class.
   *
   * @param string $class
   *   The name of the class being loaded.
   */
  static public function load($class) {
    if (class_exists($class, FALSE) || interface_exists($class, FALSE)) {
      return;
    }
    if (0 === strpos($class, 'Acquia_')) {
      $class = str_replace(array('Acquia', '_'), array('', '/'), $class);
      $file = dirname(__FILE__) . '/' . $class . '.php';
      require_once $file;
    }
  }
}
