<?php

declare(strict_types=1);

namespace Drupal\petrichor_wiki\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\petrichor\Entity\PetrichorEntityBase;

/**
 * The Wiki page entity.
 *
 * A Leantime improvement rather than a port: hierarchical KB pages (parent
 * reference), markdown-first body, optionally scoped to a project (empty
 * project = global knowledge base).
 *
 * Revisionable because PRODUCT.md measures the KB against a git-backed
 * markdown wiki: without history there is nothing to compare. The revision
 * log message is the agent-facing half of that — "what changed" is derivable
 * from a diff, "why" is not, and only the writer knows it at write time.
 *
 *
 * There is deliberately no revision_data_table, langcode or
 * revision_translation_affected key: the entity is not translatable, and
 * declaring them would add three columns and a second revision table that
 * nothing reads. 'revision_default' is likewise absent on purpose —
 * ContentEntityType::__construct() supplies it, and declaring it is an error.
 */
#[ContentEntityType(
  id: 'petrichor_wiki_page',
  label: new TranslatableMarkup('Wiki page'),
  label_collection: new TranslatableMarkup('Wiki pages'),
  label_singular: new TranslatableMarkup('wiki page'),
  label_plural: new TranslatableMarkup('wiki pages'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
    'owner' => 'uid',
    'revision' => 'revision_id',
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
      'revision-revert' => 'Drupal\Core\Entity\Form\RevisionRevertForm',
      'revision-delete' => 'Drupal\Core\Entity\Form\RevisionDeleteForm',
    ],
    'route_provider' => [
      'html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider',
      'revision' => 'Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider',
    ],
  ],
  links: [
    'canonical' => '/kb/{petrichor_wiki_page}',
    'add-form' => '/kb/add',
    'edit-form' => '/kb/{petrichor_wiki_page}/edit',
    'delete-form' => '/kb/{petrichor_wiki_page}/delete',
    'collection' => '/kb',
    'version-history' => '/kb/{petrichor_wiki_page}/revisions',
    'revision' => '/kb/{petrichor_wiki_page}/revisions/{petrichor_wiki_page_revision}/view',
    'revision-revert-form' => '/kb/{petrichor_wiki_page}/revisions/{petrichor_wiki_page_revision}/revert',
    'revision-delete-form' => '/kb/{petrichor_wiki_page}/revisions/{petrichor_wiki_page_revision}/delete',
  ],
  admin_permission: 'administer petrichor',
  base_table: 'petrichor_wiki_page',
  revision_table: 'petrichor_wiki_page_revision',
  show_revision_ui: TRUE,
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log',
  ],
  field_ui_base_route: 'petrichor_wiki.settings',
)]
class WikiPage extends PetrichorEntityBase implements RevisionLogInterface {

  // Applied here rather than on PetrichorEntityBase. The base class is shared
  // by all six Petrichor entity types, and promoting it to
  // EditorialContentEntityBase would drag a published field onto every one of
  // them — turning a single schema conversion into six.
  use RevisionLogEntityTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    // Guarded on the definition rather than assumed:
    // revisionLogBaseFieldDefinitions() throws outright when the entity type
    // carries no revision metadata keys, and the class has to be able to
    // describe itself in the state the conversion update starts from — a
    // definition that only works after its own migration cannot be tested
    // before it.
    if ($entity_type->isRevisionable()) {
      $fields += static::revisionLogBaseFieldDefinitions($entity_type);
    }

    // Inherited fields that must travel with each revision.
    //
    // 'uid' is the one that bites:
    // EntityOwnerTrait::ownerBaseFieldDefinitions() sets translatable but
    // never revisionable, so without this override every old revision
    // silently reports the *current* owner.
    //
    // 'created' and 'uuid' stay non-revisionable — they are facts about the
    // page, not about a version of it, and they never change.
    foreach (['label', 'uid', 'changed'] as $inherited) {
      $fields[$inherited]->setRevisionable(TRUE);
    }

    $fields['body'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Body'))
      ->setDescription(t('Write in Markdown — the Markdown text format renders it.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 0])
      ->setDisplayOptions('view', ['type' => 'text_default', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // parent/project/weight are revisionable for one reason: a revision has to
    // be an honest snapshot of the page, and where a page sits in the tree is
    // part of what the page *is* in a KB. Left non-revisionable they would live
    // in the base table only, so viewing a year-old revision would show today's
    // location and reverting would restore the text but not the structure — a
    // quiet lie in exactly the feature whose job is to be trustworthy.
    //
    // Accepted cost: reverting also moves the page back in the tree. That is
    // the correct reading of "revert this page to how it was", and three int
    // columns in the revision table is a cheap price for it.
    $fields['parent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Parent page'))
      ->setDescription(t('Leave empty for a top-level page.'))
      ->setSetting('target_type', 'petrichor_wiki_page')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['project'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Project'))
      ->setDescription(t('Leave empty for the global knowledge base.'))
      ->setSetting('target_type', 'petrichor_project')
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Weight'))
      ->setDescription(t('Sort order among sibling pages.'))
      ->setDefaultValue(0)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 8])
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   *
   * Forks a revision by default on every edit.
   *
   * Nothing in Drupal does this for us: ContentEntityForm only forks when the
   * user ticks a checkbox, and MCP never sees a form. Leaving it to each write
   * path means the entity would look revisionable while the history stayed one
   * row forever — the failure mode most likely to survive review unnoticed,
   * because everything except the history itself keeps working.
   *
   * Defaulting on rather than off is a judgement about this entity type in
   * particular: for a knowledge base the history IS the product, so an
   * unrecorded edit is the expensive mistake and a surplus revision is the
   * cheap one. Callers that genuinely want an in-place correction opt out with
   * setSyncing(TRUE) — the same signal core uses for writes that represent no
   * editorial act (imports, config sync, derived-data repair).
   *
   * Consequence for the entity form: ContentEntityForm's "Create new revision"
   * checkbox only ever calls setNewRevision(TRUE) and never FALSE, so it cannot
   * fight this — but it defaults to unchecked and hides the log message field
   * behind itself, which would leave a human editor recording history with no
   * way to say why. The form needs that checkbox defaulted on (or removed) for
   * this entity type.
   *
   * Note this never runs during the revisionable conversion in
   * petrichor_wiki.install: SqlContentEntityStorage::restore() bypasses all
   * pre/post-save operations by design.
   */
  public function preSave(EntityStorageInterface $storage): void {
    // Nothing below exists on a non-revisionable definition, and this class
    // has to survive being asked to save in the state its own conversion
    // update starts from.
    if (!$this->getEntityType()->isRevisionable()) {
      parent::preSave($storage);
      return;
    }

    $original = $this->getOriginal();

    if (!$this->isNew() && !$this->isSyncing()) {
      $this->setNewRevision(TRUE);
    }

    if ($this->isNewRevision() && !$this->isSyncing()) {
      // Who wrote this version and when are facts about the save, not values a
      // caller gets to supply — and they must be re-stamped rather than
      // defaulted, because on a fork the fields still hold the *previous*
      // revision's author and timestamp. Skipped when syncing so an importer
      // replaying real history keeps its own dates.
      $this->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $this->setRevisionUserId((int) \Drupal::currentUser()->id());

      // The log message carries over the same way, and that one is a lie worth
      // preventing: a fresh revision would be labelled with the reasoning
      // behind an older edit. Cleared only when it is byte-identical to the
      // previous revision's, so a caller that set a message this save keeps it.
      // Cost of that test: repeating the same message verbatim on consecutive
      // edits drops the second. A blank "why" is a much smaller wrong than a
      // confidently misattributed one.
      if ($original && $this->getRevisionLogMessage() === $original->getRevisionLogMessage()) {
        $this->setRevisionLogMessage('');
      }
    }

    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   *
   * Keeps the existing log message when an edit deliberately does not fork a
   * revision, so an in-place correction cannot blank the recorded reason for
   * the version it is correcting. Mirrors BlockContent::preSaveRevision().
   */
  public function preSaveRevision(EntityStorageInterface $storage, \stdClass $record): void {
    parent::preSaveRevision($storage, $record);

    if (!$this->getEntityType()->isRevisionable()) {
      return;
    }

    if (!$this->isNewRevision() && $this->getOriginal() && empty($record->revision_log)) {
      $record->revision_log = $this->getOriginal()->getRevisionLogMessage();
    }
  }

}
