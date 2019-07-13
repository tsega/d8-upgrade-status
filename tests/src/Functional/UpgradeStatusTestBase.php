<?php

namespace Drupal\Tests\upgrade_status\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Defines shared functions used by some of the functional tests.
 */
abstract class UpgradeStatusTestBase extends BrowserTestBase {

  /**
   * Modules to install.
   *
   * @var array
   */
  public static $modules = [
    'upgrade_status',
    'upgrade_status_test_error',
    'upgrade_status_test_no_error',
    'upgrade_status_test_submodules_a',
    'upgrade_status_test_contrib_error',
    'upgrade_status_test_contrib_no_error',
  ];

  /**
   * Perform a full scan on all test modules.
   */
  protected function runFullScan() {
    $edit = [
      'contrib[data][data][upgrade_status]' => TRUE,
      'custom[data][data][upgrade_status_test_error]' => TRUE,
      'custom[data][data][upgrade_status_test_no_error]' => TRUE,
      'custom[data][data][upgrade_status_test_submodules]' => TRUE,
      'contrib[data][data][upgrade_status_test_contrib_error]' => TRUE,
      'contrib[data][data][upgrade_status_test_contrib_no_error]' => TRUE,
    ];
    $this->drupalPostForm('admin/reports/upgrade', $edit, 'Scan selected');
  }

}
