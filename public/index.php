<?php
// public/index.php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Router\Router;
use Dotenv\Dotenv;

// --- Ensure application uses Dhaka timezone for native PHP functions ---
date_default_timezone_set('Asia/Dhaka'); // <--- important: Dhaka time for date(), time(), etc.

// Load environment variables (safe load)
$projectRoot = dirname(__DIR__);
if (file_exists($projectRoot . '/.env')) {
    Dotenv::createImmutable($projectRoot)->safeLoad();
}

// Determine request early
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$isApi = (strpos($uri, '/api/') === 0);

// ----------------- JSON error handlers (must run early) -----------------
ini_set('display_errors', '0'); // don't send raw HTML errors to client
error_reporting(E_ALL);

function sendJson(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload);
    exit;
}

// convert errors to exceptions so we can handle uniformly
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// uncaught exceptions: JSON for API, simple HTML for pages
set_exception_handler(function ($e) use ($isApi) {
    error_log(sprintf("Uncaught exception: %s in %s:%s\n%s", $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));
    $payload = [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage()
    ];

    if ($isApi) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload);
            exit;
        }
        echo json_encode($payload);
        exit;
    }

    // Non-API: show a small HTML error page (do not expose stack in production)
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Server error</title></head><body>";
    echo "<h1>Server error</h1><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    echo "</body></html>";
    exit;
});

// shutdown handler: catch fatal errors and return JSON if API
register_shutdown_function(function () use ($isApi) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        error_log('Fatal error: ' . print_r($err, true));
        $payload = [
            'success' => false,
            'message' => 'Server fatal error',
            'error' => $err['message'] ?? 'fatal'
        ];
        if ($isApi) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($payload);
                exit;
            } else {
                echo json_encode($payload);
                exit;
            }
        } else {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo "<!doctype html><html><body><h1>Fatal error</h1><pre>" . htmlspecialchars($err['message']) . "</pre></body></html>";
            exit;
        }
    }
});
// ----------------- end handlers -----------------

// Basic CORS for development (adjust in production)
if (php_sapi_name() !== 'cli') {
    // Allowed origins (comma-separated) from env or fallback
    $envOrigins = $_ENV['APP_CORS_ORIGIN'] ?? 'http://localhost:5173';
    $allowedOrigins = array_map('trim', explode(',', $envOrigins));

    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // Decide Access-Control-Allow-Origin value
    if ($requestOrigin && in_array($requestOrigin, $allowedOrigins, true)) {
        $allowOriginHeader = $requestOrigin;
        $allowCredentials = true;
    } elseif (in_array('*', $allowedOrigins, true)) {
        $allowOriginHeader = '*';
        $allowCredentials = false;
    } else {
        $allowOriginHeader = $allowedOrigins[0] ?? 'http://localhost:5173';
        $allowCredentials = true;
    }

    header('Access-Control-Allow-Origin: ' . $allowOriginHeader);

    if ($allowCredentials && $allowOriginHeader !== '*') {
        header('Access-Control-Allow-Credentials: true');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    // IMPORTANT: include X-HTTP-Method-Override and other custom headers here
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With, X-HTTP-Method-Override, Origin');

    header('Access-Control-Expose-Headers: Content-Length, X-Requested-With');

    // Preflight short-circuit
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit(0);
    }

    // ---- Method override support ----
    // If client used X-HTTP-Method-Override header, convert it into the REQUEST_METHOD used by router.
    // This must run BEFORE your routing logic, so controllers see the effective method.
    $override = null;
    if (!empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
        $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'];
    } elseif (!empty($_POST['_method'])) {
        // When PHP parsed a multipart/form-data POST, $_POST is available here.
        $override = $_POST['_method'];
    }

    if ($override) {
        $override = strtoupper(trim($override));
        // Allow only expected override verbs
        $allowed = ['PUT', 'PATCH', 'DELETE'];
        if (in_array($override, $allowed, true)) {
            // set for router to see
            $_SERVER['REQUEST_METHOD'] = $override;
        }
    }
}

// handle preflight
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// instantiate router
$router = new Router();

/* --------------------------------------------------------------------------
   Register routes (copy/extend as needed)
   -------------------------------------------------------------------------- */
/* Auth */
$router->post('/api/v1/auth/register', 'App\Controllers\AuthController@register');
$router->post('/api/v1/auth/login', 'App\Controllers\AuthController@login');
$router->post('/api/v1/auth/forgot-password', 'App\Controllers\AuthController@forgotPassword');
$router->post('/api/v1/auth/reset-password', 'App\Controllers\AuthController@resetPassword');
$router->get('/api/v1/auth/me', 'App\Controllers\AuthController@me');
$router->post('/api/v1/auth/change-password', 'App\Controllers\AuthController@changePassword');

/* Users */
$router->get('/api/v1/users', 'App\Controllers\UsersController@index');
$router->post('/api/v1/users', 'App\Controllers\UsersController@store');
$router->get('/api/v1/users/{id}', 'App\Controllers\UsersController@show');
$router->put('/api/v1/users/{id}', 'App\Controllers\UsersController@update');
$router->delete('/api/v1/users/{id}', 'App\Controllers\UsersController@destroy');

/* Wings / Subwings */
$router->get('/api/v1/wings', 'App\Controllers\WingsController@index');
$router->post('/api/v1/wings', 'App\Controllers\WingsController@store');
$router->get('/api/v1/wings/{id}', 'App\Controllers\WingsController@show');
$router->put('/api/v1/wings/{id}', 'App\Controllers\WingsController@update');
$router->delete('/api/v1/wings/{id}', 'App\Controllers\WingsController@destroy');

$router->get('/api/v1/subwings', 'App\Controllers\SubWingsController@index');
$router->post('/api/v1/subwings', 'App\Controllers\SubWingsController@store');
$router->get('/api/v1/subwings/{id}', 'App\Controllers\SubWingsController@show');
$router->put('/api/v1/subwings/{id}', 'App\Controllers\SubWingsController@update');
$router->delete('/api/v1/subwings/{id}', 'App\Controllers\SubWingsController@destroy');
$router->get('/api/v1/wings/{id}/subwings', 'App\Controllers\SubWingsController@indexByWing');

/* Locations */
$router->get('/api/v1/locations', 'App\Controllers\LocationsController@index');
$router->post('/api/v1/locations', 'App\Controllers\LocationsController@store');
$router->get('/api/v1/locations/{id}', 'App\Controllers\LocationsController@show');
$router->put('/api/v1/locations/{id}', 'App\Controllers\LocationsController@update');
$router->patch('/api/v1/locations/{id}/status', 'App\Controllers\LocationsController@updateStatus'); // new
$router->delete('/api/v1/locations/{id}', 'App\Controllers\LocationsController@destroy');


/* SOPs & files */
$router->get('/api/v1/sops', 'App\Controllers\SopsController@index');
$router->post('/api/v1/sops', 'App\Controllers\SopsController@store');
$router->get('/api/v1/sops/{id}', 'App\Controllers\SopsController@show');
$router->put('/api/v1/sops/{id}', 'App\Controllers\SopsController@update');
$router->delete('/api/v1/sops/{id}', 'App\Controllers\SopsController@destroy');

$router->post('/api/v1/sops/{id}/files', 'App\Controllers\SopFilesController@upload');
$router->get('/api/v1/sops/{id}/files', 'App\Controllers\SopFilesController@listBySop');
$router->get('/api/v1/sop-files/{id}', 'App\Controllers\SopFilesController@download');
$router->delete('/api/v1/sop-files/{id}', 'App\Controllers\SopFilesController@destroy');

/* Rooms & Meetings */
$router->get('/api/v1/rooms', 'App.Controllers\RoomsController@index');
$router->post('/api/v1/rooms', 'App\Controllers\RoomsController@store');
$router->get('/api/v1/rooms/{id}', 'App\Controllers\RoomsController@show');
$router->put('/api/v1/rooms/{id}', 'App\Controllers\RoomsController@update');
$router->delete('/api/v1/rooms/{id}', 'App\Controllers\RoomsController@destroy');

$router->get('/api/v1/meetings', 'App\Controllers\MeetingsController@index');
$router->post('/api/v1/meetings', 'App\Controllers\MeetingsController@store');
$router->get('/api/v1/meetings/{id}', 'App.Controllers\MeetingsController@show');
$router->put('/api/v1/meetings/{id}', 'App\Controllers\MeetingsController@update');
$router->delete('/api/v1/meetings/{id}', 'App.Controllers\MeetingsController@destroy');

$router->post('/api/v1/meetings/{id}/status', 'App\Controllers\MeetingStatusesController@create');
$router->get('/api/v1/meetings/{id}/status', 'App\Controllers\MeetingStatusesController@index');

/* Notices & Forms */
$router->get('/api/v1/notices', 'App\Controllers\NoticesController@index');
$router->post('/api/v1/notices', 'App.Controllers\NoticesController@store');
$router->get('/api/v1/notices/{id}', 'App\Controllers\NoticesController@show');
$router->put('/api/v1/notices/{id}', 'App.Controllers\NoticesController@update');
$router->delete('/api/v1/notices/{id}', 'App.Controllers\NoticesController@destroy');

$router->get('/api/v1/forms', 'App\Controllers\FormsController@index');
$router->post('/api/v1/forms', 'App\Controllers\FormsController@store');
$router->get('/api/v1/forms/{id}', 'App\Controllers\FormsController@show');
$router->put('/api/v1/forms/{id}', 'App\Controllers\FormsController@update');
$router->delete('/api/v1/forms/{id}', 'App\Controllers\FormsController@destroy');

/* RACI */
$router->get('/api/v1/raci', 'App\Controllers\RaciMatricesController@index');
$router->post('/api/v1/raci', 'App\Controllers\RaciMatricesController@store');
$router->get('/api/v1/raci/{id}', 'App\Controllers\RaciMatricesController@show');
$router->put('/api/v1/raci/{id}', 'App\Controllers\RaciMatricesController@update');
$router->delete('/api/v1/raci/{id}', 'App\Controllers\RaciMatricesController@destroy');

$router->get('/api/v1/raci/{id}/roles', 'App\Controllers\RaciRolesController@byRaci');
$router->post('/api/v1/raci/roles', 'App.Controllers\RaciRolesController@store');
$router->delete('/api/v1/raci/roles/{id}', 'App.Controllers\RaciRolesController@destroy');

/* Helpdesk */
$router->get('/api/v1/helpdesk/tickets', 'App\Controllers\HelpdeskTicketsController@index');
$router->post('/api/v1/helpdesk/tickets', 'App\Controllers\HelpdeskTicketsController@store');
$router->get('/api/v1/helpdesk/tickets/{id}', 'App\Controllers\HelpdeskTicketsController@show');
$router->put('/api/v1/helpdesk/tickets/{id}', 'App\Controllers\HelpdeskTicketsController@update');
$router->delete('/api/v1/helpdesk/tickets/{id}', 'App\Controllers\HelpdeskTicketsController@destroy');

// Additional endpoints your frontend components expect:
$router->get('/api/v1/helpdesk/users', 'App\Controllers\HelpdeskTicketsController@getUsers');
$router->post('/api/v1/helpdesk/tickets/{id}/trash', 'App\Controllers\HelpdeskTicketsController@moveToTrash');

// Optional admin route to purge trashed older than 30 days (call from cron or admin UI)
$router->post('/api/v1/helpdesk/purge-trashed', 'App\Controllers\HelpdeskTicketsController@purgeTrashed');

/* Helpdesk Members */
$router->get('/api/v1/helpdesk/members', 'App\Controllers\HelpdeskMembersController@index');
$router->post('/api/v1/helpdesk/members', 'App\Controllers\HelpdeskMembersController@store');
$router->get('/api/v1/helpdesk/members/{id}', 'App\Controllers\HelpdeskMembersController@show');
$router->put('/api/v1/helpdesk/members/{id}', 'App\Controllers\HelpdeskMembersController@update');
$router->delete('/api/v1/helpdesk/members/{id}', 'App\Controllers\HelpdeskMembersController@destroy');


/* Health check */
$router->get('/', function () {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok', 'app' => $_ENV['APP_URL'] ?? '']);
});

// ---------- Dispatch ----------------------------------------------------
try {
    ob_start();
    $router->dispatch($method, $uri);
    $output = ob_get_clean();
    // If route produced output (HTML or JSON), send it as-is.
    echo $output;
} catch (Throwable $e) {
    // Exception handler will handle, but as a fallback:
    error_log('Dispatch error: ' . $e->getMessage());
    if ($isApi && !headers_sent()) {
        sendJson(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()], 500);
    } else {
        // Non-API fallback
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo "<!doctype html><html><body><h1>Server error</h1><pre>" . htmlspecialchars($e->getMessage()) . "</pre></body></html>";
    }
}
