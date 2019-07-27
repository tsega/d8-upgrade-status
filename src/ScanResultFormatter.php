<?php

namespace Drupal\upgrade_status;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Format scan results for display or export.
 */
class ScanResultFormatter {

  use StringTranslationTrait;

  /**
   * Upgrade status scan result storage.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $scanResultStorage;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs a \Drupal\upgrade_status\Controller\ScanResultFormatter.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory
   *   The key/value factory.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    KeyValueFactoryInterface $key_value_factory,
    DateFormatterInterface $dateFormatter,
    TimeInterface $time
  ) {
    $this->scanResultStorage = $key_value_factory->get('upgrade_status_scan_results');
    $this->dateFormatter = $dateFormatter;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('keyvalue'),
      $container->get('date.formatter'),
      $container->get('datetime.time')
    );
  }

  /**
   * Get scanning result for an extension.
   *
   * @param Extension $extension
   *   Drupal extension object.
   * @return null|array
   *   Scan results array or null if no scan results are saved.
   */
  public function getRawResult(Extension $extension) {
    $scan_results = $this->scanResultStorage->get($extension->getName());
    if (!empty($scan_results)) {
      $scan_results = json_decode($scan_results, TRUE);
    }
    return $scan_results;
  }

  /**
   * Format results output for an extension.
   *
   * @return array
   *   Build array.
   */
  public function formatResult(Extension $extension) {
    $result = $this->getRawResult($extension);
    $info = $extension->info;
    $label = $info['name'] . (!empty($info['version']) ? ' ' . $info['version'] : '');

    // This project was not yet scanned or the scan results were removed.
    if (empty($result)) {
      return [
        '#title' => $label,
        'data' => [
          '#type' => 'markup',
          '#markup' => $this->t('No deprecation scanning data available.'),
        ],
      ];
    }

    if (isset($result['data']['totals'])) {
      $project_error_count = $result['data']['totals']['file_errors'];
    }
    else {
      $project_error_count = 0;
    }

    $build = [
      '#title' => $label,
      'date' => [
        '#type' => 'markup',
        '#markup' => '<div class="list-description">' . $this->t('Scanned on @date.', ['@date' => $this->dateFormatter->format($result['date'])]) . '</div>',
        '#weight' => -10,
      ],
    ];
    if (!empty($result['plans'])) {
      $build['plans'] = [
        '#type' => 'markup',
        '#markup' => '<div class="list-description">' . $result['plans'] . '</div>',
        '#weight' => 50,
      ];
    }

    // If this project had no known issues found, report that.
    if ($project_error_count === 0) {
      $build['data'] = [
        '#type' => 'markup',
        '#markup' => $this->t('No known issues found.'),
        '#weight' => 5,
      ];
      return $build;
    }

    // Otherwise prepare list of errors in a table.
    $table = [
      '#type' => 'table',
      '#attributes' => [
        'class' => ['upgrade-status-summary'],
      ],
      '#header' => [
        'status' => $this->t('Status'),
        'filename' => $this->t('File name'),
        'line' => $this->t('Line'),
        'issue' => $this->t('Error'),
      ],
      '#weight' => 100,
    ];

    $hasFixNow = FALSE;
    foreach ($result['data']['files'] as $filepath => $errors) {
      foreach ($errors['messages'] as $error) {

        // Remove the Drupal root directory and allow paths and namespaces to wrap.
        // Emphasize filename as it may show up in the middle of the info.
        $short_path = str_replace(DRUPAL_ROOT . '/', '', $filepath);
        $short_path = str_replace('/', '&#8203;/&#8203;', $short_path);
        if (strpos($short_path, 'in context of')) {
          $short_path = preg_replace('!/([^/]+)( \(in context of)!', '/<strong>\1</strong>\2', $short_path);
          $short_path = str_replace('\\', '&#8203;\\&#8203;', $short_path);
        }
        else {
          $short_path = preg_replace('!/([^/]+)$!', '/<strong>\1</strong>', $short_path);
        }

        // @todo could be more accurate with reflection but not sure it is even possible as the reflected
        //   code may not be in the runtime at this point (eg. functions in include files)
        //   see https://www.php.net/manual/en/reflectionfunctionabstract.getfilename.php
        //   see https://www.php.net/manual/en/reflectionclass.getfilename.php

        // Link to documentation for a function in this specific Drupal version.
        $api_version = preg_replace('!^(8\.\d+)\..+$!', '\1', \Drupal::VERSION) . '.x';
        $api_link = 'https://api.drupal.org/api/drupal/' . $api_version . '/search/';
        $formatted_error = preg_replace('!deprecated function ([^(]+)\(\)!', 'deprecated function <a target="_blank" href="' . $api_link . '\1">\1()</a>', $error['message']);

        // Replace deprecated class links.
        if (preg_match('!class (Drupal\\\\.+)\.( |$)!', $formatted_error, $found)) {
          if (preg_match('!Drupal\\\\([a-z_0-9A-Z]+)\\\\(.+)$!', $found[1], $namespace)) {

            $path_parts = explode('\\', $namespace[2]);
            $class = array_pop($path_parts);
            if (in_array($namespace[1], ['Component', 'Core'])) {
              $class_file = 'core!lib!Drupal!' . $namespace[1];
            }
            elseif (in_array($namespace[1], ['KernelTests', 'FunctionalTests', 'FunctionalJavascriptTests', 'Tests'])) {
              $class_file = 'core!tests!Drupal!' . $namespace[1];
            }
            else {
              $class_file = 'core!modules!' . $namespace[1] . '!src';
            }

            if (count($path_parts)) {
              $class_file .= '!' . join('!', $path_parts);
            }

            $class_file .= '!' . $class . '.php';
            $api_link = 'https://api.drupal.org/api/drupal/' . $class_file . '/class/' . $class . '/' . $api_version;
            $formatted_error = str_replace($found[1], '<a target="_blank" href="' . $api_link . '">' . $found[1] . '</a>', $formatted_error);
          }
        }

        // Allow error messages to wrap.
        $formatted_error = str_replace('\\', '&#8203;\\&#8203;', $formatted_error);

        $error_class = 'known-warnings';
        $level_label = $this->t('Check manually');
        if (!empty($error['upgrade_status_category'])) {
          if ($error['upgrade_status_category'] == 'ignore') {
            $level_label = $this->t('Ignore');
            $error_class = 'known-ignore';
          }
          elseif ($error['upgrade_status_category'] == 'later') {
            $level_label = $this->t('Fix later');
          }
          elseif (in_array($error['upgrade_status_category'], ['safe', 'old'])) {
            $level_label = $this->t('Fix now');
            $error_class = 'known-errors';
            $hasFixNow = TRUE;
          }
        }

        $table[] = [
          '#attributes' => [
            'class' => [$error_class],
          ],
          'status' => [
            '#type' => 'markup',
            '#markup' => $level_label,
            '#wrapper_attributes' => [
              'class' => ['status-info'],
            ],
          ],
          'filename' => [
            '#type' => 'markup',
            '#markup' => $short_path,
          ],
          'line' => [
            '#type' => 'markup',
            '#markup' => $error['line'],
          ],
          'issue' => [
            '#type' => 'markup',
            '#markup' => $formatted_error,
          ],
        ];
      }
    }

    $summary = [];
    if (!empty($result['data']['totals']['upgrade_status_split']['error'])) {
      $summary[] = $this->formatPlural($result['data']['totals']['upgrade_status_split']['error'], '@count error found.', '@count errors found.');
    }
    if (!empty($result['data']['totals']['upgrade_status_split']['warning'])) {
      $summary[] = $this->formatPlural($result['data']['totals']['upgrade_status_split']['warning'], '@count warning found.', '@count warnings found.');
    }
    if ($hasFixNow) {
      if (!empty($extension->info['project'])) {
        $summary[] = $this->t('Items categorized "Fix now" are uses of deprecated APIs from community unsupported core versions.');
      }
      else {
        $summary[] = $this->t('Items categorized "Fix now" are uses of deprecated APIs in custom code from current or older Drupal core version.');
      }
    }
    $build['summary'] = [
      '#type' => '#markup',
      '#markup' => '<div class="list-description">' . join(' ', $summary) . '</div>',
      '#weight' => 5,
    ];

    $build['data'] = $table;

    $build['export'] = [
      '#type' => 'link',
      '#title' => $this->t('Export report'),
      '#name' => 'export',
      '#url' => Url::fromRoute(
        'upgrade_status.export',
        [
          'type' => $extension->getType(),
          'project_machine_name' => $extension->getName(),
        ]
      ),
      '#attributes' => [
        'class' => [
          'button',
          'button--primary',
        ],
      ],
      '#weight' => 200,
    ];

    return $build;
  }

  /**
   * Format results output for an extension.
   *
   * @return array
   *   Build array.
   */
  public function formatAsciiResult(Extension $extension) {
    $result = $this->getRawResult($extension);
    $info = $extension->info;
    $label = $info['name'] . (!empty($info['version']) ? ' ' . $info['version'] : '');

    // This project was not yet scanned or the scan results were removed.
    if (empty($result)) {
      return [
        '#title' => $label,
        'data' => [
          '#type' => 'markup',
          '#markup' => $this->t('No deprecation scanning data available.'),
        ],
      ];
    }

    if (isset($result['data']['totals'])) {
      $project_error_count = $result['data']['totals']['file_errors'];
    }
    else {
      $project_error_count = 0;
    }

    $build = [
      '#title' => $label,
      'date' => [
        '#type' => 'markup',
        '#markup' =>  wordwrap($this->t('Scanned on @date.', ['@date' => $this->dateFormatter->format($result['date'])]), 80, "\n", true),
        '#weight' => -10,
      ],
    ];
    if (!empty($result['plans'])) {
      $build['plans'] = [
        '#type' => 'markup',
        '#markup' => wordwrap($result['plans'], 80, "\n", true),
        '#weight' => 50,
      ];
    }

    // If this project had no known issues found, report that.
    if ($project_error_count === 0) {
      $build['data'] = [
        '#type' => 'markup',
        '#markup' => $this->t('No known issues found.'),
        '#weight' => 5,
      ];
      return $build;
    }

    // Otherwise prepare list of errors in a table.
    $table = [
      '#type' => 'table',
      '#attributes' => [
        'class' => ['upgrade-status-summary'],
      ],
      '#header' => [
        'status' => $this->t('Status'),
        'filename' => $this->t('File name'),
        'line' => $this->t('Line'),
        'issue' => $this->t('Error'),
      ],
      '#weight' => 100,
    ];

    $hasFixNow = FALSE;
    foreach ($result['data']['files'] as $filepath => $errors) {
      foreach ($errors['messages'] as $error) {

        // Remove the Drupal root directory and allow paths and namespaces to wrap.
        // Emphasize filename as it may show up in the middle of the info.
        $short_path = str_replace(DRUPAL_ROOT . '/', '', $filepath);
        // $short_path = str_replace('/', '&#8203;/&#8203;', $short_path);
        // if (strpos($short_path, 'in context of')) {
        //   $short_path = preg_replace('!/([^/]+)( \(in context of)!', '/<strong>\1</strong>\2', $short_path);
        //   $short_path = str_replace('\\', '&#8203;\\&#8203;', $short_path);
        // }
        // else {
        //   $short_path = preg_replace('!/([^/]+)$!', '/<strong>\1</strong>', $short_path);
        // }

        // @todo could be more accurate with reflection but not sure it is even possible as the reflected
        //   code may not be in the runtime at this point (eg. functions in include files)
        //   see https://www.php.net/manual/en/reflectionfunctionabstract.getfilename.php
        //   see https://www.php.net/manual/en/reflectionclass.getfilename.php

        // Link to documentation for a function in this specific Drupal version.
        $api_version = preg_replace('!^(8\.\d+)\..+$!', '\1', \Drupal::VERSION) . '.x';
        $api_link = 'https://api.drupal.org/api/drupal/' . $api_version . '/search/';
        $formatted_error = preg_replace('!deprecated function ([^(]+)\(\)!', 'deprecated function <a target="_blank" href="' . $api_link . '\1">\1()</a>', $error['message']);

        // Replace deprecated class links.
        if (preg_match('!class (Drupal\\\\.+)\.( |$)!', $formatted_error, $found)) {
          if (preg_match('!Drupal\\\\([a-z_0-9A-Z]+)\\\\(.+)$!', $found[1], $namespace)) {

            $path_parts = explode('\\', $namespace[2]);
            $class = array_pop($path_parts);
            if (in_array($namespace[1], ['Component', 'Core'])) {
              $class_file = 'core!lib!Drupal!' . $namespace[1];
            }
            elseif (in_array($namespace[1], ['KernelTests', 'FunctionalTests', 'FunctionalJavascriptTests', 'Tests'])) {
              $class_file = 'core!tests!Drupal!' . $namespace[1];
            }
            else {
              $class_file = 'core!modules!' . $namespace[1] . '!src';
            }

            if (count($path_parts)) {
              $class_file .= '!' . join('!', $path_parts);
            }

            $class_file .= '!' . $class . '.php';
            $api_link = 'https://api.drupal.org/api/drupal/' . $class_file . '/class/' . $class . '/' . $api_version;
            $formatted_error = str_replace($found[1], '<a target="_blank" href="' . $api_link . '">' . $found[1] . '</a>', $formatted_error);
          }
        }

        // Allow error messages to wrap.
        $formatted_error = str_replace('\\', '&#8203;\\&#8203;', $formatted_error);

        $error_class = 'known-warnings';
        $level_label = $this->t('Check manually');
        if (!empty($error['upgrade_status_category'])) {
          if ($error['upgrade_status_category'] == 'ignore') {
            $level_label = $this->t('Ignore');
            $error_class = 'known-ignore';
          }
          elseif ($error['upgrade_status_category'] == 'later') {
            $level_label = $this->t('Fix later');
          }
          elseif (in_array($error['upgrade_status_category'], ['safe', 'old'])) {
            $level_label = $this->t('Fix now');
            $error_class = 'known-errors';
            $hasFixNow = TRUE;
          }
        }

        $table[] = [
          '#attributes' => [
            'class' => [$error_class],
          ],
          'status' => [
            '#type' => 'markup',
            '#markup' => $level_label,
            '#wrapper_attributes' => [
              'class' => ['status-info'],
            ],
          ],
          'filename' => [
            '#type' => 'markup',
            '#markup' => $short_path,
          ],
          'line' => [
            '#type' => 'markup',
            '#markup' => $error['line'],
          ],
          'issue' => [
            '#type' => 'markup',
            '#markup' => $formatted_error,
          ],
        ];

        $short_path = wordwrap($short_path, 74, "\n", true);
        $error_line = $error['line'];

        $asciiTable = [];
        $asciiTable[] = <<<EOT
FILE: $short_path
--------------------------------------------------------------------------------
    LINE    |  STATUS      | MESSAGE
--------------------------------------------------------------------------------
$error_line | $level_label | $formatted_error
--------------------------------------------------------------------------------
EOT;
      }
    }

    $summary = [];
    if (!empty($result['data']['totals']['upgrade_status_split']['error'])) {
      $summary[] = $this->formatPlural($result['data']['totals']['upgrade_status_split']['error'], '@count error found.', '@count errors found.');
    }
    if (!empty($result['data']['totals']['upgrade_status_split']['warning'])) {
      $summary[] = $this->formatPlural($result['data']['totals']['upgrade_status_split']['warning'], '@count warning found.', '@count warnings found.');
    }
    if ($hasFixNow) {
      if (!empty($extension->info['project'])) {
        $summary[] = $this->t('Items categorized "Fix now" are uses of deprecated APIs from community unsupported core versions.');
      }
      else {
        $summary[] = $this->t('Items categorized "Fix now" are uses of deprecated APIs in custom code from current or older Drupal core version.');
      }
    }
    $build['summary'] = [
      '#type' => '#markup',
      '#markup' => '<div class="list-description">' . join(' ', $summary) . '</div>',
      '#weight' => 5,
    ];

    $build['data'] = implode("\n", $asciiTable);

    $build['export'] = [
      '#type' => 'link',
      '#title' => $this->t('Export report'),
      '#name' => 'export',
      '#url' => Url::fromRoute(
        'upgrade_status.export',
        [
          'type' => $extension->getType(),
          'project_machine_name' => $extension->getName(),
        ]
      ),
      '#attributes' => [
        'class' => [
          'button',
          'button--primary',
        ],
      ],
      '#weight' => 200,
    ];

    return $build;
  }

  /**
   * Format date/time.
   *
   * @param int $time
   *   (optional) Timestamp. Current time used if not specified.
   * @param string $format
   *   (optiona) Format identifier. Default format is used it not specified.
   *
   * @return string
   *   Formatted date/time.
   */
  public function formatDateTime($time = 0, $format = '') {
    if (empty($time)) {
      $time = $this->time->getCurrentTime();
    }
    return $this->dateFormatter->format($time, $format);
  }

}