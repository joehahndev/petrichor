<?php

declare(strict_types=1);

namespace Drupal\petrichor_memory\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\petrichor\Entity\PetrichorEntityBase;

/**
 * The Memory entity — one fact an agent chose to keep.
 *
 * Source attribution is the owner (the agent's service user); no separate
 * source field, so authorship can't drift from the access system. Status
 * 'archived' is what `forget` produces — hard delete is human-only via the
 * UI, per the threat model.
 */
#[ContentEntityType(
  id: 'petrichor_memory',
  label: new TranslatableMarkup('Memory'),
  label_collection: new TranslatableMarkup('Memories'),
  label_singular: new TranslatableMarkup('memory'),
  label_plural: new TranslatableMarkup('memories'),
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
    'canonical' => '/pm/memory/{petrichor_memory}',
    'add-form' => '/pm/memory/add',
    'edit-form' => '/pm/memory/{petrichor_memory}/edit',
    'delete-form' => '/pm/memory/{petrichor_memory}/delete',
    'collection' => '/pm/memories',
  ],
  admin_permission: 'administer petrichor',
  base_table: 'petrichor_memory',
  field_ui_base_route: 'petrichor_memory.settings',
)]
class Memory extends PetrichorEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['fact'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Fact'))
      ->setDescription(t('The remembered fact, in markdown. The title is its short summary.'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 0])
      ->setDisplayOptions('view', ['type' => 'text_default', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['project'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Project scope'))
      ->setDescription(t('Leave empty for a global memory.'))
      ->setSetting('target_type', 'petrichor_project')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Type'))
      ->setRequired(TRUE)
      ->setDefaultValue('learning')
      ->setSetting('allowed_values', [
        'decision' => t('Decision'),
        'preference' => t('Preference'),
        'reference' => t('Reference'),
        'learning' => t('Learning'),
      ])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 6])
      ->setDisplayOptions('view', ['type' => 'list_default', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['tags'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Tags'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 8])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('active')
      ->setSetting('allowed_values', [
        'active' => t('Active'),
        'archived' => t('Archived'),
      ])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
