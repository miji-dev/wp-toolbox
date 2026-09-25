<?php
// Settings that are on by default, set to WordPress' own behaviour. See tests/bootstrap.php.
return [
	'head' => ['remove_generator' => false, 'remove_rsd' => false, 'remove_shortlink' => false, 'disable_emojis' => false],
	'security' => ['disable_xmlrpc' => false, 'disable_file_editor' => false, 'security_headers' => false],
	'admin' => ['hide_update_notices_for_non_admins' => false, 'disable_admin_email_check' => false],
	'dashboard' => ['hide_widgets' => []],
	'media' => ['clean_filenames' => false],
	'login' => ['logo' => 'wordpress', 'logo_links_home' => false],
	'environment' => ['badge' => 'off', 'noindex' => false],
	'editor' => ['disable_remote_patterns' => false, 'disable_block_directory' => false, 'disable_openverse' => false],
	'elementor' => ['open_in_elementor' => false],
];
