<?php

namespace Drupal\Tests\upgrade_status\Functional;

use Drupal\Core\Url;

/**
 * Tests analysing sample projects.
 *
 * @group upgrade_status
 */
class UpgradeStatusAnalyseTest extends UpgradeStatusTestBase {

  public function testAnalyser() {
    $this->drupalLogin($this->drupalCreateUser(['administer software updates']));
    $this->runFullScan();

    /** @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface $key_value */
    $key_value = \Drupal::service('keyvalue')->get('upgrade_status_scan_results');

    // Check if the project has scan result in the keyValueStorage.
    $this->assertTrue($key_value->has('upgrade_status_test_error'));
    $this->assertTrue($key_value->has('upgrade_status_test_no_error'));
    $this->assertTrue($key_value->has('upgrade_status_test_submodules'));
    $this->assertTrue($key_value->has('upgrade_status_test_contrib_error'));
    $this->assertTrue($key_value->has('upgrade_status_test_contrib_no_error'));

    // The project upgrade_status_test_submodules_a shouldn't have scan result,
    // because it's a submodule of 'upgrade_status_test_submodules',
    // and we always want to run the scan on root modules.
    $this->assertFalse($key_value->has('upgrade_status_test_submodules_a'));

    $project = $key_value->get('upgrade_status_test_error');
    $this->assertNotEmpty($project);
    $report = json_decode($project, TRUE);
    $this->assertEquals(2, $report['data']['totals']['file_errors']);
    $this->assertCount(2, $report['data']['files']);
    $file = reset($report['data']['files']);
    $message = $file['messages'][0];
    $this->assertEquals("Syntax error, unexpected T_STRING on line 3", $message['message']);
    $this->assertEquals(3, $message['line']);
    $file = next($report['data']['files']);
    $message = $file['messages'][0];
    $this->assertEquals("Call to deprecated function menu_cache_clear_all(). Deprecated in Drupal 8.6.0, will be removed before Drupal 9.0.0. Use\n\Drupal::cache('menu')->invalidateAll() instead.", $message['message']);
    $this->assertEquals(10, $message['line']);

    $project = $key_value->get('upgrade_status_test_no_error');
    $this->assertNotEmpty($project);
    $report = json_decode($project, TRUE);
    $this->assertEquals(0, $report['data']['totals']['file_errors']);
    $this->assertCount(0, $report['data']['files']);

    $project = $key_value->get('upgrade_status_test_contrib_error');
    $this->assertNotEmpty($project);
    $report = json_decode($project, TRUE);
    $this->assertEquals(1, $report['data']['totals']['file_errors']);
    $this->assertCount(1, $report['data']['files']);
    $file = reset($report['data']['files']);
    $message = $file['messages'][0];
    $this->assertEquals("Call to deprecated function format_string(). Deprecated in Drupal 8.0.0, will be removed before Drupal 9.0.0.\nUse \Drupal\Component\Render\FormattableMarkup.", $message['message']);
    $this->assertEquals(15, $message['line']);
  }

}
