<?php

declare(strict_types=1);

namespace Drupal\petrichor\Form;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\RedundantEditableConfigNamesTrait;
use Drupal\Core\Url;
use Drupal\petrichor\Theme\PetrichorNegotiator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Site-level settings for the Petrichor suite.
 *
 * One switch so far: whether the workspace routes render in the companion
 * theme. Kept deliberately small — anything that is an opinion about the
 * whole site (front page, default theme, JSON:API posture) belongs in a
 * recipe, not here.
 */
final class PetrichorSettingsForm extends ConfigFormBase {

  use RedundantEditableConfigNamesTrait;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected ThemeHandlerInterface $themeHandler,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('theme_handler'),
      $container->get('cache_tags.invalidator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'petrichor_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $installed = $this->themeHandler->themeExists(PetrichorNegotiator::THEME);

    if (!$installed) {
      $form['unavailable'] = [
        '#type' => 'item',
        '#markup' => $this->t('The Petrichor theme is not installed on this site, so there is nothing to switch to. Install it on the <a href=":appearance">Appearance</a> page first — it does not have to be the site default; this setting is what puts it in front of the workspace pages.', [
          ':appearance' => Url::fromRoute('system.themes_page')->toString(),
        ]),
        '#weight' => -10,
      ];
    }

    $form['use_workspace_theme'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use the Petrichor theme for the workspace'),
      '#description' => $this->t('When this is on, the board, Today, the dashboard, the wiki and the Petrichor entity pages (everything under /pm and /kb) render in the Petrichor theme instead of the site theme. Nothing else on the site changes — every other page, including the Petrichor settings pages under /admin, keeps the theme it has now. Leave it off to run Petrichor inside your existing design.'),
      '#config_target' => 'petrichor.settings:use_workspace_theme',
      '#disabled' => !$installed,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    // Theme negotiation is not expressed as a cache tag on the pages it
    // affects, so without this the workspace keeps serving pages rendered in
    // the old theme until something else invalidates them. One admin action,
    // one flush of rendered output — cheaper than teaching every workspace
    // page to depend on this config.
    $this->cacheTagsInvalidator->invalidateTags(['rendered']);
  }

}
