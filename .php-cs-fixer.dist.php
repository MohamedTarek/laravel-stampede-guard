<?php

$finder = PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config']);

// The code is shared with the 2.x/3.x branches, which Pint formats. Pint leaves `new Foo` without
// parentheses and allows class constants without a visibility keyword; @PSR12 would rewrite both.
// Both rules were renamed in php-cs-fixer 3.x, in different releases, so each name is detected on its own.
// new_with_braces -> new_with_parentheses happened in php-cs-fixer v3.32.0.
$newWithParentheses = class_exists(PhpCsFixer\Fixer\Operator\NewWithParenthesesFixer::class);
// visibility_required -> modifier_keywords happened in php-cs-fixer v3.88.0.
$modifierKeywords = class_exists(PhpCsFixer\Fixer\ClassNotation\ModifierKeywordsFixer::class);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => false,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        ($newWithParentheses ? 'new_with_parentheses' : 'new_with_braces') => false,
        ($modifierKeywords ? 'modifier_keywords' : 'visibility_required') => ['elements' => ['property', 'method']],
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
