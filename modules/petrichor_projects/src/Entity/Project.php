<?php

declare(strict_types=1);

namespace Drupal\petrichor_projects\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\petrichor\Entity\PetrichorEntityBase;

/**
 * The Project entity.
 *
 * Leantime equivalent: Projects (with client label folded in as a field
 * instead of a separate Clients entity — revisit if client management is
 * needed).
 */
#[ContentEntityType(
  id: 'petrichor_project',
  label: new TranslatableMarkup('Project'),
  label_collection: new TranslatableMarkup('Projects'),
  label_singular: new TranslatableMarkup('project'),
  label_plural: new TranslatableMarkup('projects'),
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
    'canonical' => '/pm/project/{petrichor_project}',
    'add-form' => '/pm/project/add',
    'edit-form' => '/pm/project/{petrichor_project}/edit',
    'delete-form' => '/pm/project/{petrichor_project}/delete',
    'collection' => '/pm/projects',
  ],
  admin_permission: 'administer petrichor',
  base_table: 'petrichor_project',
  field_ui_base_route: 'petrichor_projects.settings',
)]
class Project extends PetrichorEntityBase {

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

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('open')
      ->setSetting('allowed_values', [
        'open' => t('Open'),
        'on_hold' => t('On hold'),
        'closed' => t('Closed'),
        'archived' => t('Archived'),
      ])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 5])
      ->setDisplayOptions('view', ['type' => 'list_default', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['client'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Client'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['start_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Start date'))
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 15])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['end_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('End date'))
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 16])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['color'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Color'))
      ->setDescription(t('Hex color used for boards and timelines, e.g. #1f6feb.'))
      ->setSetting('max_length', 7)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 20])
      ->setDisplayConfigurable('form', TRUE);

    $fields['members'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Members'))
      ->setSetting('target_type', 'user')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 25,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
