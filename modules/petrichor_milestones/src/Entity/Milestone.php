<?php

declare(strict_types=1);

namespace Drupal\petrichor_milestones\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\petrichor\Entity\PetrichorEntityBase;

/**
 * The Milestone entity.
 *
 * Leantime equivalent: Milestones / timeline (gantt) entries.
 */
#[ContentEntityType(
  id: 'petrichor_milestone',
  label: new TranslatableMarkup('Milestone'),
  label_collection: new TranslatableMarkup('Milestones'),
  label_singular: new TranslatableMarkup('milestone'),
  label_plural: new TranslatableMarkup('milestones'),
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
    'canonical' => '/pm/milestone/{petrichor_milestone}',
    'add-form' => '/pm/milestone/add',
    'edit-form' => '/pm/milestone/{petrichor_milestone}/edit',
    'delete-form' => '/pm/milestone/{petrichor_milestone}/delete',
    'collection' => '/pm/milestones',
  ],
  admin_permission: 'administer petrichor',
  base_table: 'petrichor_milestone',
  field_ui_base_route: 'petrichor_milestones.settings',
)]
class Milestone extends PetrichorEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

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
      ->setDefaultValue('planned')
      ->setSetting('allowed_values', [
        'planned' => t('Planned'),
        'active' => t('Active'),
        'done' => t('Done'),
      ])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 4])
      ->setDisplayOptions('view', ['type' => 'list_default', 'weight' => 4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['start_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Start date'))
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['end_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('End date'))
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 7])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['color'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Color'))
      ->setSetting('max_length', 7)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 9])
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

}
