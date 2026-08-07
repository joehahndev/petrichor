<?php

declare(strict_types=1);

namespace Drupal\petrichor_markdown\Plugin\Filter;

use Drupal\filter\Attribute\Filter;
use Drupal\filter\Plugin\FilterInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\petrichor_markdown\MarkdownExtensionRegistry;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Converts Markdown to HTML using league/commonmark.
 *
 * Raw HTML in the source is escaped and unsafe link schemes are blocked, so
 * this is safe to expose to any user who can use the Markdown text format.
 *
 * Other modules extend the syntax through the
 * `petrichor_markdown.extension_provider` tag rather than by filtering the
 * generated HTML afterwards: a post-filter would have to re-implement the
 * tokenizer's code-span and fence exclusions by hand, and with
 * `html_input: escape` it cannot tell a deliberately-escaped HTML sample from
 * prose.
 */
#[Filter(
  id: 'petrichor_markdown',
  title: new TranslatableMarkup('Markdown (CommonMark)'),
  description: new TranslatableMarkup('Renders CommonMark markdown. Raw HTML is escaped.'),
  type: FilterInterface::TYPE_MARKUP_LANGUAGE,
)]
final class MarkdownFilter extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * Lazily built converter.
   *
   * CommonMarkConverter is final in 2.x and an Environment is sealed the first
   * time it converts, so the environment is assembled once and reused. Never
   * rebuild it mid-request.
   */
  protected ?MarkdownConverter $converter = NULL;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected MarkdownExtensionRegistry $extensions,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('petrichor_markdown.extension_registry'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    if (trim($text) === '') {
      return new FilterProcessResult($text);
    }
    if ($this->converter === NULL) {
      $environment = new Environment([
        'html_input' => 'escape',
        'allow_unsafe_links' => FALSE,
      ]);
      // Core first, or the extensions have no markdown to extend.
      $environment->addExtension(new CommonMarkCoreExtension());
      foreach ($this->extensions->getExtensions() as $extension) {
        $environment->addExtension($extension);
      }
      $this->converter = new MarkdownConverter($environment);
    }
    try {
      $html = (string) $this->converter->convert($text);
    }
    finally {
      // Drained even on failure: a half-filled buffer must not attach itself
      // to whatever body is rendered next.
      $metadata = $this->extensions->takeMetadata();
    }
    // merge() is clone-based (CacheableMetadata::merge), so the processed text
    // survives. Merging is also what keeps this addCacheTags rather than
    // setCacheTags, which would clobber.
    return (new FilterProcessResult($html))->merge($metadata);
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return $this->t('CommonMark markdown is supported: headings, lists, links, code blocks, tables come via extensions later. Raw HTML is escaped.');
  }

}
