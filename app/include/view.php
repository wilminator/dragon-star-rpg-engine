<?php
// Page templates: Twig (https://twig.symfony.com), a Jinja2 look-alike,
// so templates will carry over to the Python version with light edits.
// Pages do their own work -- checks, form handling, queries -- and hand
// plain values to a template in app/templates/:
//
//   render('admin/users.html.twig', ['accounts' => $accounts]);
//
// Output is HTML-escaped automatically. strict_variables makes a
// misspelled variable an error rather than a silent blank.
//
// Also available in every template: csrf_field() (see csrf.php).

require_once __DIR__ . '/../../vendor/autoload.php';
require_once INCLUDE_GAME . 'csrf.php';

function view(): \Twig\Environment {
    static $twig = null;
    if ($twig === null) {
        $cache = getenv('TWIG_CACHE') ?: sys_get_temp_dir() . '/dragonstar-twig';
        $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates'), [
            'cache' => $cache,
            'auto_reload' => true,
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', 'csrf_field', ['is_safe' => ['html']]));
    }
    return $twig;
}

function render(string $template, array $values = []): void {
    echo view()->render($template, $values);
}
