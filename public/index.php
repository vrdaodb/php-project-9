<?php

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use DI\Container;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container();
$container->set('renderer', function () {
    return new PhpRenderer(__DIR__ . '/../templates', ['flash' => []]);
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

$app->get('/', function ($request, $response) {
    $content = $this->get('renderer')->fetch('index.phtml');
    return $this->get('renderer')->render($response, 'layout.phtml', [
        'content' => $content
    ]);
});

$app->run();
