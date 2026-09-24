<?php

declare(strict_types=1);

namespace Miji\Toolbox\Support;

use Closure;
use InvalidArgumentException;
use WP_Block_Type_Registry;

/**
 * Blocks that must not appear: they render nothing on the frontend and are removed from the editor's inserter.
 */
final class HiddenBlocks {
	/** @var non-empty-string */
	private readonly string $handle;

	/**
	 * @param string $handle script handle for the editor, unique per module
	 * @param Closure(string): bool $matches whether a block name is hidden
	 */
	public function __construct(string $handle, private readonly Closure $matches) {
		if ($handle === '') {
			throw new InvalidArgumentException('Script handle must not be empty.');
		}
		$this->handle = $handle;
	}

	public function register(): void {
		add_filter('pre_render_block', [$this, 'skip'], 10, 2);
		add_action('enqueue_block_editor_assets', [$this, 'enqueueEditorScript']);
	}

	/**
	 * @param array<string, mixed> $block
	 */
	public function skip(?string $output, array $block): ?string {
		return is_string($block['blockName'] ?? null) && ($this->matches)($block['blockName']) ? '' : $output;
	}

	public function enqueueEditorScript(): void {
		wp_register_script($this->handle, false, ['wp-blocks', 'wp-dom-ready'], false, true);
		wp_add_inline_script($this->handle, sprintf(
			'wp.domReady(function () { %s.forEach(function (name) { if (wp.blocks.getBlockType(name)) { wp.blocks.unregisterBlockType(name); } }); });',
			wp_json_encode($this->names()),
		));
		wp_enqueue_script($this->handle);
	}

	/**
	 * @return list<string> registered block names that are hidden
	 */
	public function names(): array {
		return array_values(array_filter(
			array_keys(WP_Block_Type_Registry::get_instance()->get_all_registered()),
			fn (string $name): bool => ($this->matches)($name),
		));
	}
}
