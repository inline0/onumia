<?php

namespace Onumia\Lib\YahnisElsts\PluginUpdateChecker\v5p7;

use Onumia\Lib\YahnisElsts\PluginUpdateChecker\v5\PucFactory as MajorFactory;
use Onumia\Lib\YahnisElsts\PluginUpdateChecker\v5p7\PucFactory as MinorFactory;
require __DIR__ . '/Puc/v5p7/Autoloader.php';
new Autoloader();
require __DIR__ . '/Puc/v5p7/PucFactory.php';
require __DIR__ . '/Puc/v5/PucFactory.php';
//Register classes defined in this version with the factory.
foreach (array('Onumia\Lib\Plugin\UpdateChecker' => Plugin\UpdateChecker::class, 'Onumia\Lib\Theme\UpdateChecker' => Theme\UpdateChecker::class, 'Onumia\Lib\Vcs\PluginUpdateChecker' => Vcs\PluginUpdateChecker::class, 'Onumia\Lib\Vcs\ThemeUpdateChecker' => Vcs\ThemeUpdateChecker::class, 'GitHubApi' => Vcs\GitHubApi::class, 'BitBucketApi' => Vcs\BitBucketApi::class, 'GitLabApi' => Vcs\GitLabApi::class) as $pucGeneralClass => $pucVersionedClass) {
    MajorFactory::addVersion($pucGeneralClass, $pucVersionedClass, '5.7');
    //Also add it to the minor-version factory in case the major-version factory
    //was already defined by another, older version of the update checker.
    MinorFactory::addVersion($pucGeneralClass, $pucVersionedClass, '5.7');
}
