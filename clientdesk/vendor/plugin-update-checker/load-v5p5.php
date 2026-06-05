<?php

namespace YahnisElsts\PluginUpdateChecker\v5p5;

require __DIR__ . '/load-v5p7.php';

if (!class_exists(PucFactory::class) && class_exists(\YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::class)) {
    class_alias(\YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::class, PucFactory::class);
}
