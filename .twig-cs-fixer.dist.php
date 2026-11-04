<?php

declare(strict_types=1);

use TwigCsFixer\Config\Config;
use TwigCsFixer\File\Finder;
use TwigCsFixer\Rules\Whitespace\IndentRule;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Standard\TwigCsFixer;

$ruleset = new Ruleset();
$ruleset->addStandard(new TwigCsFixer());
$ruleset->overrideRule(new IndentRule(useTab: true));

$finder = Finder::create()->in(__DIR__ . '/src/templates');

$config = new Config();
$config->setRuleset($ruleset);
$config->setFinder($finder);
$config->setCacheFile(null);

return $config;
