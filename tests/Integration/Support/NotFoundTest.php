<?php

declare(strict_types=1);

namespace Miji\Toolbox\Tests\Integration\Support;

use Miji\Toolbox\Support\NotFound;
use WP_UnitTestCase;

final class NotFoundTest extends WP_UnitTestCase {
	public function test_turns_the_request_into_a_404(): void {
		$this->go_to(home_url('/?feed=rss2'));

		NotFound::send();

		$this->assertTrue(is_404());
		$this->assertFalse(is_feed(), 'otherwise WordPress would still render the feed');
		$this->assertFalse(is_comment_feed());
	}

	public function test_wordpress_does_not_redirect_the_404_elsewhere(): void {
		// redirect_canonical (template_redirect 10) would otherwise "guess" a URL for the 404 and 301 there,
		// e.g. /some-page/embed/ -> /some-page/
		$page = self::factory()->post->create(['post_type' => 'page', 'post_name' => 'some-page']);
		$this->go_to(get_permalink($page));

		NotFound::send();

		$this->assertFalse(apply_filters('redirect_canonical', get_permalink($page), home_url('/some-page/embed/')));
		$this->assertFalse(apply_filters('do_redirect_guess_404_permalink', true));
		$this->assertNull(redirect_canonical(home_url('/some-page/embed/'), false));
	}
}
