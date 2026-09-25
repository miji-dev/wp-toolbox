<?php

declare(strict_types=1);

namespace Miji\Toolbox\Settings;

enum FieldType: string {
	case Bool = 'bool';
	/** One value out of a fixed list. */
	case Choice = 'choice';
	/** Any number of values out of a (possibly runtime-resolved) list, e.g. post types or roles. */
	case Multi = 'multi';
	case Int = 'int';
	/** One line of plain text. */
	case Text = 'text';
	/** Plain text with line breaks. */
	case Textarea = 'textarea';
	/** Hex colour like #1a2b3c, or empty. */
	case Color = 'color';
	/** Media library attachment ID, 0 = none. */
	case Attachment = 'attachment';
	/** Local date and time in the site's time zone, "2026-10-01T18:30" (HTML datetime-local), or empty. */
	case DateTime = 'datetime';
}
