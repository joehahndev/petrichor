<?php

declare(strict_types=1);

namespace Drupal\petrichor_tasks\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\petrichor\Entity\PetrichorEntityBase;

/**
 * The Task entity.
 *
 * Leantime equivalent: To-Dos. The milestone reference field is attached by
 * the petrichor_milestones module (hook_entity_base_field_info) so this
 * module has no hard dependency on it.
 */
#[ContentEntityType(
  id: 'petrichor_task',
  label: new TranslatableMarkup('Task'),
  label_collection: new TranslatableMarkup('Tasks'),
  label_singular: new TranslatableMarkup('task'),
  label_plural: new TranslatableMarkup('tasks'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
    'owner' => 'uid',
  ],
  handlers: [
    'view_builder' => 'Drupal\Core\Entity\EntityViewBuilder',
    'list_builder' => 'Drupal\petrichor\PetrichorEntityListBuilder',
    'views_data' => 'Drupal\views\EntityViewsData',
    'access' => 'Drupal\petrichor\PetrichorEntityAccessControlHandler',
    'form' => [
      'default' => 'Drupal\petrichor\Form\PetrichorEntityForm',
      'add' => 'Drupal\petrichor\Form\PetrichorEntityForm',
      'edit' => 'Drupal\petrichor\Form\PetrichorEntityForm',
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider',
    ],
  ],
  links: [
    'canonical' => '/pm/task/{petrichor_task}',
    'add-form' => '/pm/task/add',
    'edit-form' => '/pm/task/{petrichor_task}/edit',
    'delete-form' => '/pm/task/{petrichor_task}/delete',
    'collection' => '/pm/tasks',
  ],
  admin_permission: 'administer petrichor',
  base_table: 'petrichor_task',
  field_ui_base_route: 'petrichor_tasks.settings',
)]
class Task extends PetrichorEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Description'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 0])
      ->setDisplayOptions('view', ['type' => 'text_default', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['project'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Project'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'petrichor_project')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('backlog')
      ->setSetting('allowed_values', [
        'backlog' => t('Backlog'),
        'todo' => t('To do'),
        'in_progress' => t('In progress'),
        'review' => t('In review'),
        'done' => t('Done'),
      ])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 4])
      ->setDisplayOptions('view', ['type' => 'list_default', 'weight' => 4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['priority'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Priority'))
      ->setRequired(TRUE)
      ->setDefaultValue('medium')
      ->setSetting('allowed_values', [
        'low' => t('Low'),
        'medium' => t('Medium'),
        'high' => t('High'),
        'urgent' => t('Urgent'),
      ])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 6])
      ->setDisplayOptions('view', ['type' => 'list_default', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['assignee'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Assignee'))
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 8,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['due_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Due date'))
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['estimate_hours'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Estimate (hours)'))
      ->setSetting('precision', 8)
      ->setSetting('scale', 2)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 12])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['tags'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Tags'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 14])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Blocked is orthogonal to progress, not a point on it: a task can be
    // in progress AND waiting on someone else. A sixth column would force a
    // choice between those two facts and crowd a board built for low choice
    // density, so it is a flag the card carries in whatever column it sits in.
    $fields['blocked'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Blocked'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 17])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['blocked_note'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Blocked on'))
      ->setDescription(t('What this is waiting for. Shown on the card.'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 18])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['parent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Parent task'))
      ->setDescription(t('Set to make this a subtask.'))
      ->setSetting('target_type', 'petrichor_task')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 16,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
