<?php

declare(strict_types=1);

namespace Drupal\petrichor;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generic list builder for Petrichor entities.
 *
 * Collections are intentionally minimal; Views-based dashboards (kanban,
 * timeline) replace these as the suite matures.
 */
final class PetrichorEntityListBuilder extends EntityListBuilder {

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $instance = new static($entity_type, $container->get('entity_type.manager')->getStorage($entity_type->id()));
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Title');
    $header['changed'] = $this->t('Updated');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $row['id'] = $entity->id();
    $row['label'] = $entity->toLink();
    $row['changed'] = $entity->hasField('changed')
      ? $this->dateFormatter->format((int) $entity->get('changed')->value, 'short')
      : '';
    return $row + parent::buildRow($entity);
  }

}
