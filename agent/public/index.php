<?php

/**
 * CodeIgniter 4 web entry point.
 * Serves the REST API and documentation pages.
 */

// Path to the front controller
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

// Ensure the current directory is pointing to the front controller's directory
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

// Load the Paths config file and instantiate
require FCPATH . '../app/Config/Paths.php';
$paths = new Config\Paths();

// Load the framework bootstrap
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'Boot.php';

exit(\CodeIgniter\Boot::bootWeb($paths));
