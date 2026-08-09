<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_tags\Functional;

use Drupal\Tests\system\Functional\Module\GenericModuleTestBase;

/**
 * Installs, uninstalls and reinstalls cleanly, and has readable help.
 *
 * Uninstalling this module takes the tags field — and its data — off every
 * entity type it was attached to, the same trade-off petrichor_milestones
 * makes for the 'milestone' field on tasks. Core's own check is what pins
 * that the uninstall path itself does not error.
 *
 * @group petrichor
 */
class GenericTest extends GenericModuleTestBase {

}
