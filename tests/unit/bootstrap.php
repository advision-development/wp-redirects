<?php
/**
 * Unit test bootstrap: no WordPress. Only pure classes are tested here.
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\Advision\Redirects\Autoloader::register( dirname( __DIR__, 2 ) . '/src' );
