<?php

declare(strict_types=1);

namespace Drupal\petrichor_timesheets\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\petrichor\Entity\PetrichorEntityBase;

/**
 * The Timesheet entry entity.
 *
 * Leantime equivalent: Timesheets. The label doubles as the "what did you
 * work on" summary; the owner (uid) is who logged the time.
 */
#[ContentEntityType(
  id: 'petrichor_timesheet',
  label: new TranslatableMarkup('Timesheet entry'),
  label_collection: new TranslatableMarkup('Timesheets'),
  label_singular: new TranslatableMarkup('timesheet entry'),
  label_plural: new TranslatableMarkup('timesheet entries'),
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
    'canonical' => '/pm/timesheet/{petrichor_timesheet}',
    'add-form' => '/pm/timesheet/add',
    'edit-form' => '/pm/timesheet/{petrichor_timesheet}/edit',
    'delete-form' => '/pm/timesheet/{petrichor_timesheet}/delete',
    'collection' => '/pm/timesheets',
  ],
  admin_permission: 'administer petrichor',
  base_table: 'petrichor_timesheet',
  field_ui_base_route: 'petrichor_timesheets.settings',
)]
class Timesheet extends PetrichorEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['task'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Task'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'petrichor_task')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Date'))
      ->setRequired(TRUE)
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['hours'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Hours'))
      ->setRequired(TRUE)
      ->setSetting('precision', 6)
      ->setSetting('scale', 2)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['kind'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Type of work'))
      ->setDefaultValue('development')
      ->setSetting('allowed_values', [
        'development' => t('Development'),
        'design' => t('Design'),
        'meeting' => t('Meeting'),
        'research' => t('Research'),
        'admin' => t('Administration'),
        'other' => t('Other'),
      ])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 8])
      ->setDisplayOptions('view', ['type' => 'list_default', 'weight' => 8])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
