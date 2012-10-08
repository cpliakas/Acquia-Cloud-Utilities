<?php

/**
 * @file
 * Classes that interact with low level Acquia Hosting services.
 */

/**
 * Class that non-Drupal apps can use to get Acquia database credentials.
 */
class Acquia_Cloud {

  /**
   * The site name, i.e. "account", "accountstg", "accountdev".
   *
   * @var string
   */
  protected $siteName;

  /**
   * Directory to the settings files.
   *
   * @var string
   */
  protected $sitePhpDir;

  /**
   * The site stage, i.e. "dev", "test", or "prod".
   *
   * @var string
   */
  protected $siteStage;

  /**
   * Sets the account and site information.
   *
   * @param $account
   *   The unix name of the account.
   *
   * @throws Exception
   */
  public function __construct($account) {
    $this->account = $account;
    // Identify the AH site and stage name:
    // If this is a Drupal page request, use AH environment vars.
    if (!empty($_SERVER['AH_SITE_GROUP']) && !empty($_SERVER['AH_SITE_ENVIRONMENT'])) {
      $docroot = "/var/www/html/{$_SERVER['AH_SITE_GROUP']}.{$_SERVER['AH_SITE_ENVIRONMENT']}/docroot";
    }
    // This is probably redundant with the AH env vars, but is what we used to
    // use and can't hurt.
    elseif (!empty($_SERVER['DOCUMENT_ROOT'])) {
      $docroot = $_SERVER['DOCUMENT_ROOT'];
    }
    // If this is not a page request, not AH env vars or DOCUMENT_ROOT are
    // available. Drush?
    elseif (function_exists('drush_get_option')) {
      $docroot = drush_get_option(array("r", "root"), $_SERVER['PWD']);
    }
    // Otherwise, perhaps we're a script within docroot but running on the
    // command line (e.g. scripts/run-test.sh).
    else {
      $docroot = dirname(realpath($_SERVER['SCRIPT_FILENAME']));
    }

    // Set the site information.
    if (isset($docroot) && preg_match('@/(?:var|mnt)/www/html/([a-z0-9_\.]+)/@i', $docroot, $m)) {
      $this->siteName = $m[1];
      $this->sitePhpDir = '/var/www/site-php/' . $this->siteName;
      $this->siteStage = file_get_contents($this->sitePhpDir . '/ah-site-stage');
    }
    else {
      throw new Exception('Document root not found.');
    }
  }

  /**
   * Returns the site name.
   *
   * @return
   *   A string containing the site name.
   */
  public function getSiteName() {
    return $this->siteName;
  }

  /**
   * Returns the site stage.
   *
   * @return
   *   A string containing the stage, i.e. "dev", "test", "prod", etc.
   */
  public function getSiteStage() {
    return $this->siteStage;
  }

  /**
   * Selects which database is active, returns an array of database credentials.
   *
   * @param string $db_name
   *   The name of the database as entered in the Acquia Cloud UI.
   *
   * @return array
   *   An array of credentials for the active database containing:
   *   - scheme: The type of connection specified in the connection URL.
   *   - host: The hostname of the server.
   *   - port: The port the server is listening on.
   *   - user: The database user.
   *   - pass: THe database password.
   *   - db: The name of the database.
   *   - id: The conection ID, or the value cached in DNS.
   *
   * @throws Exception
   */
  public function getActiveDatabaseCredentials($db_name) {
    // Gets all credentials for all available database servers.
    if (!$creds = $this->getAllDatabaseCredentials($db_name)) {
      throw new Exception('Credentials root not found.');
    }

    // Captures URLs.
    $db_url_ha = $creds['db']['db_url_ha'];

    // Makes sure the cached server ID is first in our HA pool.
    if ($cached_id = $this->getActiveCache($creds['db']['db_cluster_id'])) {
      if (isset($creds['db']['db_url_ha'][$cached_id])) {
        $creds['db']['db_url_ha'][$cached_id] = -1;
        asort($creds['db']['db_url_ha'], SORT_NUMERIC);
      }
    }

    // Builds URLs in the order they should be processed.
    $db_urls = array();
    foreach ($creds['db']['db_url_ha'] as $server => $value) {
      $db_urls[$server] = $db_url_ha[$server];
    }

    // Gets server credentials for the active database.
    $active_server = $this->getActiveServer($db_urls);

    // If the preferred database server changed, write this to the cache.
    if (empty($cached_id) || $cached_id != $active_server['id']) {
      $this->cacheActiveServer($db_info['db_cluster_id'], $active_server['id']);
    }

    // Returns the server information.
    return $active_server;
  }

  /**
   * Returns the MySQL port.
   *
   * @return int
   *   The MySQL port.
   */
  public function getMysqlPort() {
    return file_exists('/mnt/tmp/' . $this->account . '/ah-query-analyzer') ? 6446 : 3306;
  }

  /**
   * Get database credentials.
   *
   * @param string $db_name
   *   The name of the database as entered in the Acquia Cloud UI.
   *
   * @return array
   *   The database credentials and information.
   */
  public function getAllDatabaseCredentials($db_name) {

    // Calculates the path to the Drupal 7 settings file.
    $filepath = $this->sitePhpDir . '/D7-' . $this->siteStage . '-' . $db_name . '-settings.inc';

    // Pulls only the $conf variable from the settings file.
    // NOTE: Sourcing the entire file has Drupal assumptions and would add a lot
    // of unwanted stuff. Therefore we pull out only the variable and eval() it.
    $variable = '';
    $handle = @fopen($filepath, 'r');
    if ($handle) {
      while (($buffer = fgets($handle, 4096)) !== FALSE) {
        if ($variable || (!$variable && 0 === strpos($buffer, '$conf[\'acquia_hosting_site_info\']'))) {
          $variable .= $buffer;
        }
        if ($variable && 0 === strpos($buffer, ');')) {
          break;
        }
      }
      fclose($handle);
    }

    // Uses eval() to pull the $conf variable into the local scope.
    if ($variable) {
      $mysql_port = $this->getMysqlPort();
      eval($variable);
      if (isset($conf['acquia_hosting_site_info'])) {
        return $conf['acquia_hosting_site_info'];
      }
    }

    // If we got here, somethig went wrong.
    return array();
  }

  /**
   * Gets the cache ID of the active server from DNS.
   *
   * @param int $cluster_id
   *   An integer containing the cluster ID.
   *
   * @return string
   *   The cached ID of the last known active server.
   */
  public function getActiveCache($cluster_id) {
    $cached_id = '';

    // Manage the include path explicitly so we get our own Net/DNS.php, then
    // revert back to the original include path.
    $include_path = get_include_path();
    set_include_path('/usr/share/php');
    require_once('Net/DNS.php');
    set_include_path($include_path);

    // Instantiates resolver, gets cache ID.
    $resolver = new Net_DNS_Resolver();
    $resolver->nameservers = array('127.0.0.1', 'dns-master');
    $resolver->retry = 1;
    $resolver->retrans = 1;
    $resolver->usevc = 1;
    $response = $resolver->query('cluster-' . $cluster_id . '.mysql', 'CNAME');

    if ($response) {
      // Replace '.' with '' because the response ends in a FQDN which is like 'ded-5.'
      $cached_id = preg_replace("/\.$/", "", trim($response->answer[0]->rdatastr()));
    }
    else {
      syslog(LOG_WARNING, 'AH_CRITICAL: unrecognized server name in ' . __FILE__ . ':' . $cached_id);
    }

    return $cached_id;
  }

  /**
   * Loops through active servers and returns credentials on the first success.
   *
   * @param array $db_urls
   *   An associative array keyed by server ID to connection URL in the order
   *   they should be processed in.
   * @param int $max_attempts
   *   The number of attempts made per server before failing to the next.
   *   Defaults to 3.
   * @param int $delay_factor
   *   Number of microseconds multiplied by the attempt to determine the delay
   *   between connection attempts. Defaults to 500000 meaning the next
   *   connection attempt wil be delayed for 0.5 seconds after attempt 1, 1.0
   *   seconds after atempt 2, 1.5 seconds after attempt 3, etc.
   *
   * @return array
   *   An array of information about the server containing:
   *   - scheme: The type of connection specified in the connection URL.
   *   - host: The hostname of the server.
   *   - port: The port the server is listening on.
   *   - user: The database user.
   *   - pass: THe database password.
   *   - db: The name of the database.
   *   - id: The conection ID, or the value cached in DNS.
   *
   * @throws Exception
   */
  public function getActiveServer(array $db_urls, $max_attempts = 3, $delay_factor = 500000) {
    $active_server = array();

    // Iterates over the pool to establish a connection.
    foreach ($db_urls as $server => $url) {
      $attempt = 1;

      while ($attempt <= $max_attempts) {
        try {

          // Parses the URL, builds DSN passed to PDO.
          $parts = parse_url($url);
          $parts['db'] = ltrim($parts['path'], '/');
          unset($parts['path']);
          $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'], $parts['db']);

          // Connection failures will trow an exception. If no exceptions are
          // thrown, store the server information and break both loops.
          new PDO($dsn, $parts['user'], $parts['pass']);
          $active_server = $parts;
          $active_server['id'] = $server;
          break 2;
        }
        catch (Exception $e) {
          // Delay the next connection attempt if we aren't on the last one.
          if ($attempt < $max_attempts) {
            usleep($attempt * $delay_factor);
          }
        }

        $attempt += 1;
      }
      // If we failed to connect to this server, log a warning message before
      // moving on to the the next connection attempt.
      syslog(LOG_WARNING, "AH_WARNING: The connection to database {$db_info['name']} on $server failed after $max_attempts attempts and is now failing over. Error was: " . $e->getMessage());
    }

    // If no connection was made, show an error page and exit.
    if (empty($active_server['id'])) {
      $message = "Failed to connect to any database servers for database {$db_info['name']}.";
      syslog(LOG_ERR, $message);
      throw new Exception($message);
    }

    return $active_server;
  }

  /**
   * Cache the active server in DNS.
   *
   * @param $cluster_id
   *   The ID of the HA cluster.
   * @param $connected_id
   *   The ID if the server that was connected to.
   */
  public function cacheActiveServer($cluster_id, $connected_id) {
    $update_cmd = "sudo -u dnsuser /usr/local/sbin/nsupdate.sh cluster-$cluster_id $connected_id";
    exec(escapeshellcmd($update_cmd));
  }
}
