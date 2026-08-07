<?php

declare(strict_types=1);

namespace Drupal\petrichor\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings page for Petrichor entity types.
 *
 * Exists mainly as the field_ui_base_route anchor so Field UI's
 * "Manage fields" / "Manage display" tabs attach to each entity type.
 */
class PetrichorEntitySettingsForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'petrichor_entity_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['info'] = [
      '#markup' => $this->t('Base fields are defined in code. Enable the Field UI module to add configurable fields via the tabs above.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Nothing to save; settings live in code.
  }

}
