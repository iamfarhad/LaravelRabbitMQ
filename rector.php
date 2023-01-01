<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/Config',
        __DIR__ . '/src',
    ]);

    // register a single rule
    $rectorConfig->rule(InlineConstructorDefaultToPropertyRector::class);

    // define sets of rules
        $rectorConfig->sets([
            LevelSetList::UP_TO_PHP_81,
            SetList::CODE_QUALITY,
            SetList::CODING_STYLE,
            SetList::NAMING,
            SetList::PRIVATIZATION,
            SetList::TYPE_DECLARATION,
            SetList::ACTION_INJECTION_TO_CONSTRUCTOR_INJECTION,
            SetList::EARLY_RETURN
        ]);
};
