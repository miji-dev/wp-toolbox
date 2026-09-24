<?php
// Throwaway wp-content for the tests. Must live outside the repo: the plugin is symlinked into it,
// and a wp-content nested inside the plugin confuses WordPress' symlink handling in plugin_basename().
return sys_get_temp_dir() . '/wptb-tests-' . substr(md5(dirname(__DIR__)), 0, 8);
