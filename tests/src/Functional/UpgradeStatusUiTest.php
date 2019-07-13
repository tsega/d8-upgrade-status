<?php

namespace Drupal\Tests\upgrade_status\Functional;

use Drupal\Core\Url;

/**
 * Tests the UI before and after running scans.
 *
 * @group upgrade_status
 */
class UpgradeStatusUiTest extends UpgradeStatusTestBase {

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer software updates']));
  }

  /**
   * Test the user interface before running a scan.
   */
  public function testUiBeforeScan() {
    $this->drupalGet(Url::fromRoute('upgrade_status.report'));
    $assert_session = $this->assertSession();

    $assert_session->buttonExists('Scan selected');
    $assert_session->buttonExists('Export selected');

    // Status for every project should be 'Not scanned'.
    $status = $this->getSession()->getPage()->findAll('css', 'td.status-info');
    foreach ($status as $project_status) {
      $this->assertSame('Not scanned', $project_status->getHtml());
    }
  }

  /**
   * Test the user interface after running a scan.
   */
  public function testUiAfterScan() {
    $this->runFullScan();

    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $assert_session->buttonExists('Scan selected');
    $assert_session->buttonExists('Export selected');

    // Custom projects have 3 columns of information.
    $upgrade_status_test_error = $page->find('css', '.upgrade-status-summary-custom .project-upgrade_status_test_error');
    $this->assertCount(3, $upgrade_status_test_error->findAll('css', 'td'));
    $this->assertSame('1 error, 1 warning', strip_tags($upgrade_status_test_error->find('css', 'td.status-info')->getHtml()));

    $upgrade_status_test_no_error = $page->find('css', '.upgrade-status-summary-custom .project-upgrade_status_test_no_error');
    $this->assertCount(3, $upgrade_status_test_no_error->findAll('css', 'td'));
    $this->assertSame('No known errors', $upgrade_status_test_no_error->find('css', 'td.status-info')->getHtml());

    $upgrade_status_test_submodules = $page->find('css', '.upgrade-status-summary-custom .project-upgrade_status_test_submodules');
    $this->assertCount(3, $upgrade_status_test_submodules->findAll('css', 'td'));
    $this->assertSame('No known errors', $upgrade_status_test_submodules->find('css', 'td.status-info')->getHtml());

    // Contributed modules should have one extra column because of possible
    // available update information.
    $upgrade_status_test_contrib_error = $page->find('css', '.upgrade-status-summary-contrib .project-upgrade_status_test_contrib_error');
    $this->assertCount(4, $upgrade_status_test_contrib_error->findAll('css', 'td'));
    $this->assertSame('1 error', strip_tags($upgrade_status_test_contrib_error->find('css', 'td.status-info')->getHtml()));

    $upgrade_status_test_contrib_no_error = $page->find('css', '.upgrade-status-summary-contrib .project-upgrade_status_test_contrib_no_error');
    $this->assertCount(4, $upgrade_status_test_contrib_no_error->findAll('css', 'td'));
    $this->assertSame('No known errors', $upgrade_status_test_contrib_no_error->find('css', 'td.status-info')->getHtml());

    // Click the '2 errors' link. Should be the custom module.
    $this->clickLink('1 error, 1 warning');
    $this->assertText('Upgrade status test error ' . \Drupal::VERSION);
    $this->assertText('1 error found. 1 warning found.');
    $this->assertText('Syntax error, unexpected T_STRING on line 3');

    // Go forward to the export page and assert that still contains the results
    // as well as an export specific title.
    $this->clickLink('Export report');
    $this->assertText('Upgrade Status report');
    $this->assertText('Upgrade status test error ' . \Drupal::VERSION);
    $this->assertText('Custom modules and themes');
    $this->assertNoText('Contributed modules and themes');
    $this->assertText('1 error found. 1 warning found.');
    $this->assertText('Syntax error, unexpected T_STRING on line 3');

    // Run partial export of multiple projects.
    $edit = [
      'custom[data][data][upgrade_status_test_error]' => TRUE,
      'custom[data][data][upgrade_status_test_no_error]' => TRUE,
      'contrib[data][data][upgrade_status_test_contrib_error]' => TRUE,
    ];
    $this->drupalPostForm('admin/reports/upgrade', $edit, 'Export selected');
    $this->assertText('Upgrade Status report');
    $this->assertText('Upgrade status test contrib error ' . \Drupal::VERSION);
    $this->assertText('Upgrade status test no error ' . \Drupal::VERSION);
    $this->assertText('Upgrade status test error ' . \Drupal::VERSION);
    $this->assertNoText('Upgrade status test root module');
    $this->assertNoText('Upgrade status test contrib no error');
    $this->assertText('Contributed modules and themes');
    $this->assertText('Custom modules and themes');
    $this->assertText('1 error found. 1 warning found.');
    $this->assertText('Syntax error, unexpected T_STRING on line 3');
  }

  /**
   * Test project collection's result by checking the amount of projects.
   */
  public function testProjectCollector() {
    $this->drupalGet(Url::fromRoute('upgrade_status.report'));
    $page = $this->getSession()->getPage();

    $this->assertCount(3, $page->findAll('css', '.upgrade-status-summary-custom tr[class*=\'project-\']'));
    $this->assertCount(4, $page->findAll('css', '.upgrade-status-summary-contrib tr[class*=\'project-\']'));
  }

}
