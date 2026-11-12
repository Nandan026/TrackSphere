<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Session\Session;
use Psr\Log\LoggerInterface;

/**
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 *
 * Extend this class in any new controllers:
 * ```
 *     class Home extends BaseController
 * ```
 *
 * For security, be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */
    protected Session $session;

    /**
     * Helpers available to every controller (also autoloaded globally).
     *
     * @var list<string>
     */
    protected $helpers = ['form', 'url', 'auth', 'response', 'format', 'catalog'];

    /**
     * Guide module key for the current controller. Child controllers may set
     * this (e.g. 'shipments') or pass 'module' in the renderPage() data.
     */
    protected string $module = '';

    /**
     * @param CLIRequest|IncomingRequest $request
     *
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Caution: Do not edit this line.
        parent::initController($request, $response, $logger);

        $this->session = service('session');
    }

    /**
     * Renders a view, injecting shared layout data:
     *  - user   : authenticated user (role + permissions) or null
     *  - org    : organization summary for the user, or null
     *  - module : module key
     *  - guide  : help content for the module from Config\Guides, or null
     *
     * @param array<string, mixed> $data
     */
    protected function renderPage(string $view, array $data = []): string
    {
        $user   = current_user();
        $module = (string) ($data['module'] ?? $this->module);

        $org = null;
        if ($user !== null && ! empty($user['organization_id'])) {
            $org = [
                'id'       => $user['organization_id'],
                'name'     => $user['organization_name'] ?? '',
                'code'     => $user['organization_code'] ?? '',
                'timezone' => $user['timezone'] ?? 'Asia/Kolkata',
            ];
        }

        $guide = $module !== '' ? config('Guides')->get($module) : null;

        return view($view, array_merge([
            'user'   => $user,
            'org'    => $org,
            'module' => $module,
            'guide'  => $guide,
            // Values consumed by layouts/main.php (page title, nav highlight, header chips).
            'pageTitle'     => (string) ($data['title'] ?? 'TrackSphere'),
            'currentModule' => $module,
            'userName'      => $user['full_name'] ?? 'Guest',
            'userRole'      => $user['role_name'] ?? '',
            'orgName'       => $org['name'] ?? 'TrackSphere',
        ], $data));
    }

    /**
     * Pops a flash message array [type => message] helper for views/controllers.
     */
    protected function flash(string $type, string $message): void
    {
        $this->session->setFlashdata($type, $message);
    }
}
