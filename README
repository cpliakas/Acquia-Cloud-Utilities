# Acquia Cloud Utilities

NOTE: This repository is no longer maintained and will soon be deprecated
by an upcoming hosting release that improves the mechanism to retrieving credentials.


## Usage

```php

// Registers the autoloader. 
require_once '../library/Acquia/Loader.php';
Acquia_Loader::register();

try {
    // Use the library components.
    $acquia = new Acquia_Cloud($account);
    $creds = $acquia->getActiveDatabaseCredentials($dbname);
} catch (Exception $e) {
    Acquia_Eror::page();
}
```
