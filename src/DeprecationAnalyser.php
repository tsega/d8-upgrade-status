<?php

namespace Drupal\upgrade_status;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Exception;
use GuzzleHttp\Client;
use Nette\Neon\Neon;
use PHPStan\Command\AnalyseApplication;
use PHPStan\Command\CommandHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class DeprecationAnalyser implements DeprecationAnalyserInterface {

  use StringTranslationTrait;

  /**
   * The oldest supported core minor version.
   *
   * @var string
   */
  const CORE_MINOR_OLDEST_SUPPORTED = '8.6';

  /**
   * The error format to use to retrieve the report from PHPStan.
   *
   * @var string
   */
  const ERROR_FORMAT = 'json';

  /**
   * Upgrade status scan result storage.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $scanResultStorage;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Symfony Console input interface.
   *
   * @var \Symfony\Component\Console\Input\StringInput
   */
  protected $inputInterface;

  /**
   * Symfony Console output interface.
   *
   * @var \Symfony\Component\Console\Output\BufferedOutput
   */
  protected $outputInterface;

  /**
   * Path to the PHPStan neon configuration.
   *
   * @var string
   */
  protected $phpstanNeonPath;

  /**
   * @var string
   */
  protected $upgradeStatusTemporaryDirectory;

  /**
   * A configuration object containing upgrade_status settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * HTTP Client for drupal.org API calls.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Constructs a \Drupal\upgrade_status\DeprecationAnalyser.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory
   *   The key/value factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Symfony\Component\Console\Input\StringInput $input
   *   The Symfony Console input interface.
   * @param \Symfony\Component\Console\Output\BufferedOutput $output
   *   The Symfony Console output interface.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \GuzzleHttp\Client $http_client
   *   HTTP client.
   */
  public function __construct(
    KeyValueFactoryInterface $key_value_factory,
    LoggerInterface $logger,
    StringInput $input,
    BufferedOutput $output,
    ConfigFactoryInterface $config_factory,
    Client $http_client
  ) {
    $this->scanResultStorage = $key_value_factory->get('upgrade_status_scan_results');
    // Log errors to an upgrade status logger channel.
    $this->logger = $logger;
    $this->inputInterface = $input;
    $this->outputInterface = $output;
    $this->config = $config_factory->get('upgrade_status.settings');
    $this->httpClient = $http_client;

    $this->populateAutoLoader();

    $this->upgradeStatusTemporaryDirectory = file_directory_temp() . '/upgrade_status';
    $this->phpstanNeonPath = $this->upgradeStatusTemporaryDirectory . '/deprecation_testing.neon';
    if (!file_exists($this->phpstanNeonPath)) {
      $this->prepareTempDirectory();
      $this->createModifiedNeonFile();
    }
  }

  /**
   * Populate the class loader for PHPStan.
   */
  protected function populateAutoLoader() {
    require_once DRUPAL_ROOT . '/core/tests/bootstrap.php';
    drupal_phpunit_populate_class_loader();
  }

  /**
   * {@inheritdoc}
   */
  public function analyse(Extension $extension) {
    // Prepare for possible fatal errors while autoloading or due to issues with
    // dependencies.
    drupal_register_shutdown_function([$this, 'logFatalError'], $extension);

    // Set the autoloader for PHPStan.
    if (!isset($GLOBALS['autoloaderInWorkingDirectory'])) {
      $GLOBALS['autoloaderInWorkingDirectory'] = DRUPAL_ROOT . '/autoload.php';
    }

    $project_dir = DRUPAL_ROOT . '/' . $extension->subpath;
    $paths = $this->getDirContents($project_dir);
    foreach ($paths as $key => $file_path) {
      if (substr($file_path, -3) !== 'php'
        && substr($file_path, -7) !== '.module'
        && substr($file_path, -8) !== '.install'
        && substr($file_path, -3) !== 'inc') {
        unset($paths[$key]);
      }
    }

    $this->logger->notice($this->t("Extension @project_machine_name contains @number files to process.", ['@project_machine_name' => $extension->getName(), '@number' => count($paths)]));

    $result = [];
    $result['date'] = REQUEST_TIME;
    $result['data'] = [
      'totals' => [
        'errors' => 0,
        'file_errors' => 0,
      ],
      'files' => [],
    ];

    if (!empty($paths)) {
      $num_of_files = $this->config->get('paths_per_scan');
      // @todo: refactor and validate.
      for ($offset = 0; $offset <= count($paths); $offset += $num_of_files) {
        $files = array_slice($paths, $offset, $num_of_files);
        if (!empty($files)) {
          $raw_errors = $this->runPhpStan($files);
          $errors = json_decode($raw_errors, TRUE);
          if (!is_array($errors)) {
            continue;
          }
          $result['data']['totals']['errors'] += $errors['totals']['errors'];
          $result['data']['totals']['file_errors'] += $errors['totals']['file_errors'];
          $result['data']['files'] = array_merge($result['data']['files'], $errors['files']);
        }
      }
    }

    foreach($result['data']['files'] as $path => &$errors) {
      if (!empty($errors['messages'])) {
        foreach($errors['messages'] as &$error) {
          // Make the error more readable in case it has the deprecation text.
          $error['message'] = preg_replace('!:\s+(in|as of)!', '. Deprecated \1', $error['message']);

          // Set a default category for the messages we can't categorize.
          $error['upgrade_status_category'] = 'uncategorized';

          // Match a few variants of the deprecation message including the
          // current standard: 'Deprecated in drupal:8.5.0'.
          if (preg_match('!Deprecated (in|as of) [Dd]rupal[ :](8.\d)!', $error['message'], $version_found)) {

            // Categorize deprecations for contributed projects based on
            // community rules.
            if (!empty($extension->info['project'])) {
              // If the found deprecation is older than the oldest supported core
              // version, it should be old enough to update either way.
              if (version_compare($version_found[2], self::CORE_MINOR_OLDEST_SUPPORTED) < 0) {
                $error['upgrade_status_category'] = 'old';
              }
              // If the deprecation is not old and we are dealing with a contrib
              // module, the deprecation should be dealt with later.
              else {
                $error['upgrade_status_category'] = 'later';
              }
            }
            // For custom projects, look at this site's version specifically.
            else {
              // If the found deprecation is older or equal to the current
              // Drupal version on this site, it should be safe to update.
              if (version_compare($version_found[2], \Drupal::VERSION) <= 0) {
                $error['upgrade_status_category'] = 'safe';
              }
              else {
                $error['upgrade_status_category'] = 'later';
              }
            }
          }

          // If the deprecation is already for Drupal 10, put it in the ignore
          // category. This overwrites any categorization before intentionally.
          if (preg_match('!(will be|is) removed (before|from) [Dd]rupal[ :](10.\d)!', $error['message'])) {
            $error['upgrade_status_category'] = 'ignore';
          }

          // Sum up the error based on the category it ended up in. Split the
          // categories into two high level buckets needing attention now or
          // later for Drupal 9 compatibility. Ignore Drupal 10 here.
          @$result['data']['totals']['upgrade_status_category'][$error['upgrade_status_category']]++;
          if (in_array($error['upgrade_status_category'], ['safe', 'old'])) {
            @$result['data']['totals']['upgrade_status_split']['error']++;
          }
          elseif (in_array($error['upgrade_status_category'], ['later', 'uncategorized'])) {
            @$result['data']['totals']['upgrade_status_split']['warning']++;
          }
        }
      }
    }

    $this->logger->notice(json_encode($result));

    // For contributed projects, attempt to grab Drupal 9 plan information.
    if (!empty($extension->info['project'])) {
      /** @var \Psr\Http\Message\ResponseInterface $response */
      $response = $this->httpClient->request('GET', 'https://www.drupal.org/api-d7/node.json?field_project_machine_name=' . $extension->getName());
      if ($response->getStatusCode()) {
        $data = json_decode($response->getBody(), TRUE);
        if (!empty($data['list'][0]['field_next_major_version_info']['value'])) {
          $result['plans'] = str_replace('href="/', 'href="https://drupal.org/', $data['list'][0]['field_next_major_version_info']['value']);
          // @todo implement "replaced by" collection once drupal.org exposes
          // that in an accessible way
          // @todo once/if drupal.org deprecation testing is in place, grab
          // the status from there so we know if it improves by updating
        }
      }
    }

    // Store the analysis results in our storage bin.
    $this->scanResultStorage->set($extension->getName(), json_encode($result));
  }

  /**
   * Get directory contents recursively.
   *
   * @param string $dir
   *   Path to directory.
   * @return array
   *   The list of files found.
   */
  public function getDirContents(string $dir) {
    $results = [];
    $files = scandir($dir);
    foreach ($files as $value) {
      $path = realpath($dir . '/' . $value);
      if (!is_dir($path)) {
        $results[] = $path;
        continue;
      }
      if ($value != '.' && $value != '..') {
        $results = array_merge($results, $this->getDirContents($path, $results));
      }
    }
    return $results;
  }

  /**
   * Run PHPStan on the given paths.
   *
   * @param array $paths
   *   List of paths.
   * @return mixed
   *   Results in self::ERROR_FORMAT.
   */
  public function runPhpStan(array $paths) {
    // Analyse code in the given directory with PHPStan. The most sensible way
    // we could find was to pretend we have Symfony console inputs and outputs
    // and take the result from there. PHPStan as-is is highly tied to the
    // console and we could not identify an independent PHP API to use.
    try {
      $result = CommandHelper::begin(
        $this->inputInterface,
        $this->outputInterface,
        $paths,
        NULL,
        NULL,
        NULL,
        $this->phpstanNeonPath,
        NULL
      );
    }
    catch (Exception $e) {
      $this->logger->error($e);
    }

    $container = $result->getContainer();
    $error_formatter_service = sprintf('errorFormatter.%s', self::ERROR_FORMAT);
    if (!$container->hasService($error_formatter_service)) {
      $this->logger->error('Error formatter @formatter not found.', ['@formatter' => self::ERROR_FORMAT]);
    }
    else {
      $errorFormatter = $container->getService($error_formatter_service);
      $application = $container->getByType(AnalyseApplication::class);

      $result->handleReturn(
        $application->analyse(
          $result->getFiles(),
          $result->isOnlyFiles(),
          $result->getConsoleStyle(),
          $errorFormatter,
          $result->isDefaultLevelUsed(),
          FALSE
        )
      );

      return $this->outputInterface->fetch();
    }
  }

  /**
   * Prepare temporary directories for Upgrade Status.
   *
   * The created directories in Drupal's temporary directory are needed to
   * dynamically set a temporary directory for PHPStan's cache in the neon file
   * provided by Upgrade Status.
   *
   * @return bool
   *   True if the temporary directory is created, false if not.
   */
  protected function prepareTempDirectory() {
    $success = file_prepare_directory($this->upgradeStatusTemporaryDirectory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);

    if (!$success) {
      $this->logger->error($this->t("Unable to create temporary directory for Upgrade Status: @directory.", ['@directory' => $this->upgradeStatusTemporaryDirectory]));
      return $success;
    }

    $phpstan_cache_directory = $this->upgradeStatusTemporaryDirectory . '/phpstan';
    $success = file_prepare_directory($phpstan_cache_directory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);

    if (!$success) {
      $this->logger->error($this->t("Unable to create temporary directory for PHPStan: @directory.", ['@directory' => $phpstan_cache_directory]));
    }

    return $success;
  }

  /**
   * Creates the final config file in the temporary directory.
   *
   * @return bool
   */
  protected function createModifiedNeonFile() {
    $module_path = drupal_get_path('module', 'upgrade_status');
    $unmodified_neon_file = DRUPAL_ROOT . "/$module_path/deprecation_testing.neon";
    $config = file_get_contents($unmodified_neon_file);
    $neon = Neon::decode($config);
    $neon['parameters']['tmpDir'] = $this->upgradeStatusTemporaryDirectory . '/phpstan';
    $success = file_put_contents($this->phpstanNeonPath, Neon::encode($neon), Neon::BLOCK);

    if (!$success) {
      $this->logger->error($this->t("Couldn't write configuration for PHPStan: @file.", ['@file' => $this->phpstanNeonPath]));
    }

    return $success ? TRUE : FALSE;
  }

  /**
   * Shutdown function to handle fatal errors in the parsing process.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   Failed extension.
   */
  public function logFatalError(Extension $extension) {
    $project_name = $extension->getName();
    $result = $this->scanResultStorage->get($project_name);
    $message = error_get_last();

    if (empty($result)) {

      $this->logger->error($this->t("Fatal error occurred for @project_machine_name.", ['@project_machine_name' => $project_name]));

      $result = [];
      $result['date'] = REQUEST_TIME;
      $result['data'] = [
        'totals' => [
          'errors' => 0,
          'file_errors' => 1,
        ],
        'files' => [],
      ];

      $file_name = $message['file'];

      $result['data']['files'][$file_name] = [
        'errors' => 1,
        'messages' => [
          [
            'message' => $message['message'],
            'line' => $message['line'],
          ],
        ],
      ];

      $this->scanResultStorage->set($project_name, json_encode($result));
    }

  }

}
