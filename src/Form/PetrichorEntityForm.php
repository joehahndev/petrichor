<?php

declare(strict_types=1);

namespace Drupal\petrichor\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

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
   *
   * Where to land after a save, for an entity type that may not have a
   * collection.
   *
   * `collection` is the right destination for five of the six entity types in
   * this suite and is absent on the sixth: petrichor_memory deliberately ships
   * no collection link template, because omitting it is what leaves
   * /pm/memories free for the filterable View to claim. This form is shared by
   * all of them, so asking for a link template that only usually exists turned
   * every memory save — create, edit, even a status change — into an
   * UndefinedLinkTemplateException and a white screen.
   *
   * Falling back to the entity's own page rather than hard-coding the View's
   * route: the form is generic and must not learn which entity types happen to
   * have a View standing in for their collection, and "show me the thing I
   * just saved" is a defensible destination anywhere.
   */
  protected function redirectAfterSave(): Url {
    $entity = $this->getEntity();
    return $entity->hasLinkTemplate('collection')
      ? $entity->toUrl('collection')
      : $entity->toUrl('canonical');
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

    $form_state->setRedirectUrl($this->redirectAfterSave());
    return $result;
  }

}
