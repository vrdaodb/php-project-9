<?php

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;
use DI\Container;
use Carbon\Carbon;
use Valitron\Validator;

require __DIR__ . '/../vendor/autoload.php';

session_start();

$container = new Container();

$container->set('renderer', function () {
    return new PhpRenderer(__DIR__ . '/../templates', ['flash' => []]);
});

$container->set('flash', function () {
    return new Messages();
});

$container->set('db', function () {
    $databaseUrl = getenv('DATABASE_URL');
    if ($databaseUrl) {
        $params = parse_url($databaseUrl);
        $host = $params['host'];
        $port = $params['port'] ?? 5432;
        $dbname = ltrim($params['path'], '/');
        $user = $params['user'];
        $password = $params['pass'];
    } else {
        $host = 'localhost';
        $port = 5432;
        $dbname = 'page_analyzer';
        $user = 'postgres';
        $password = 'postgres';
    }

    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    return new \PDO($dsn, $user, $password, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
    ]);
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);
$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    $flash = $this->get('flash')->getMessages();
    $content = $this->get('renderer')->fetch('index.phtml');
    return $this->get('renderer')->render($response, 'layout.phtml', [
        'content' => $content,
        'flash' => $flash
    ]);
})->setName('home');

$app->get('/urls', function ($request, $response) {
    $db = $this->get('db');
    $stmt = $db->query("SELECT * FROM urls ORDER BY created_at DESC");
    $urls = $stmt->fetchAll();

    $flash = $this->get('flash')->getMessages();
    $content = $this->get('renderer')->fetch('urls/index.phtml', ['urls' => $urls]);
    return $this->get('renderer')->render($response, 'layout.phtml', [
        'content' => $content,
        'flash' => $flash
    ]);
})->setName('urls');

$app->post('/urls', function ($request, $response) use ($router) {
    $db = $this->get('db');
    $data = $request->getParsedBody();
    $urlInput = $data['url'] ?? '';

    $v = new Validator(['url' => $urlInput]);
    $v->rule('required', 'url')->message('URL не должен быть пустым');
    $v->rule('url', 'url')->message('Некорректный URL');
    $v->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');

    if (!$v->validate()) {
        $flash = $this->get('flash')->getMessages();
        $content = $this->get('renderer')->fetch('index.phtml', [
            'errors' => $v->errors(),
            'url' => $urlInput
        ]);
        return $this->get('renderer')->render($response->withStatus(422), 'layout.phtml', [
            'content' => $content,
            'flash' => $flash
        ]);
    }

    $parsed = parse_url($urlInput);
    $normalizedUrl = strtolower($parsed['scheme']) . '://' . strtolower($parsed['host']);

    $stmt = $db->prepare("SELECT id FROM urls WHERE name = :name");
    $stmt->execute([':name' => $normalizedUrl]);
    $existing = $stmt->fetch();

    if ($existing) {
        $this->get('flash')->addMessage('warning', 'Страница уже существует');
        return $response
            ->withHeader('Location', $router->urlFor('url', ['id' => $existing['id']]))
            ->withStatus(302);
    }

    $stmt = $db->prepare("INSERT INTO urls (name, created_at) VALUES (:name, :created_at) RETURNING id");
    $stmt->execute([
        ':name' => $normalizedUrl,
        ':created_at' => Carbon::now()
    ]);
    $id = $stmt->fetchColumn();

    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    return $response
        ->withHeader('Location', $router->urlFor('url', ['id' => $id]))
        ->withStatus(302);
})->setName('addUrl');

$app->get('/urls/{id}', function ($request, $response, array $args) {
    $db = $this->get('db');
    $stmt = $db->prepare("SELECT * FROM urls WHERE id = :id");
    $stmt->execute([':id' => $args['id']]);
    $url = $stmt->fetch();

    if (!$url) {
        return $response->withStatus(404)->write('Page not found');
    }

    $flash = $this->get('flash')->getMessages();
    $content = $this->get('renderer')->fetch('urls/show.phtml', ['url' => $url]);
    return $this->get('renderer')->render($response, 'layout.phtml', [
        'content' => $content,
        'flash' => $flash
    ]);
})->setName('url');

$app->run();
