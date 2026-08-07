<?php

declare(strict_types=1);

namespace Drupal\petrichor\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Generic add/edit form for Petrichor entities.
 */
class PetrichorEntityForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   *
   * Revisionable entities always fork, so the checkbox is a lie and the log
   * message must not hide behind it.
   *
   * WikiPage::preSave() forks on every edit regardless of what this form
   * says. Core's checkbox defaults to unchecked and #states-hides the log
   * message field behind it, so a human editor would silently record a
   * revision with no way to say why — the one field that makes history worth
   * having. Checked, disabled, and the message always visible.
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    if (isset($form['revision'])) {
      $form['revision']['#default_value'] = TRUE;
      $form['revision']['#disabled'] = TRUE;
      $form['revision']['#description'] = $this->t('Every edit is recorded. Say why below.');
    }
    if (isset($form['revision_log'])) {
      unset($form['revision_log']['#states']);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $entity = $this->getEntity();

    $args = [
      '@type' => $entity->getEntityType()->getSingularLabel(),
      '%label' => $entity->label(),
    ];
    $this->messenger()->addStatus(
      $result === SAVED_NEW
        ? $this->t('Created @type %label.', $args)
        : $this->t('Updated @type %label.', $args)
    );

    $form_state->setRedirectUrl($entity->toUrl('collection'));
    return $result;
  }

}
