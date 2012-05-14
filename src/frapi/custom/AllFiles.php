<?php
// If you remove this. You might die.
define('FRAPI_CACHE_ADAPTER', 'apc');

// Use the constant CUSTOM_MODEL to access the custom model directory
// IE: require CUSTOM_MODEL . DIRECTORY_SEPARATOR . 'ModelName.php';
// Or add an autolaoder if you are brave.

// Frapi comes with Armchair by default. You can use it or decide to remove it.
// You can find armchair at: git://github.com/till/armchair.git
//require CUSTOM_MODEL . DIRECTORY_SEPARATOR . 'ArmChair' . DIRECTORY_SEPARATOR . 'ArmChair.php';

// Other data

defined('CUSTOM_LIBRARY') || define('CUSTOM_LIBRARY', CUSTOM_PATH. DIRECTORY_SEPARATOR . 'Library');
defined('CUSTOM_LIBRARY_FRAPI_PLUGINS') ||
    define('CUSTOM_LIBRARY_FRAPI_PLUGINS', CUSTOM_LIBRARY . DIRECTORY_SEPARATOR . 'Frapi' . DIRECTORY_SEPARATOR . 'Plugins');


