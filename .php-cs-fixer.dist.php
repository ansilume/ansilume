<?php

/**
 * PHP-CS-Fixer configuration — matches Scrutinizer-CI rules.
 *
 * Run: php vendor/bin/php-cs-fixer fix --dry-run --diff
 * Fix: php vendor/bin/php-cs-fixer fix
 */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('vendor')
    ->exclude('.composer')
    ->exclude('runtime')
    ->exclude('@runtime')
    ->exclude('web/assets')
    ->exclude('docker')
    ->exclude('migrations')
    ->exclude('tests')
    ->notPath('web/js/cronstrue.min.js');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'cast_spaces' => ['space' => 'none'],
        // Disable statement_indentation in mixed PHP/HTML view files —
        // php-cs-fixer re-indents to PHP scope, but the code is inside HTML
        // context where the surrounding indentation is meaningful.
        'statement_indentation' => false,
    ])
    ->setFinder($finder);
