<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_markdown\Functional;

use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\system\Functional\Module\GenericModuleTestBase;

/**
 * Installs, uninstalls and reinstalls cleanly, and has readable help.
 *
 * The suite advertises an a-la-carte install, so "can this be turned off
 * again" is a promise rather than a detail. Core does the checking; this
 * class just enrolls the module in it.
 *
 * @group petrichor
 */
class GenericTest extends GenericModuleTestBase {

  /**
   * {@inheritdoc}
   *
   * Core refuses to uninstall a module whose filter plugin is still used by a
   * text format, and that refusal is correct: pulling the plugin out from
   * under a format would silently change how existing content renders. Every
   * filter module in core behaves the same way, and the documented answer is
   * to remove the format first — which is what the site builder would be told
   * to do in the UI, and what this does here.
   */
  protected function preUnInstallSteps(): void {
    FilterFormat::load('markdown')?->delete();
  }

}
