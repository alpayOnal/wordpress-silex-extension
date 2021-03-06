<?php

namespace Bangpound\Silex\Security;

use Bangpound\Process\InjectRequestGlobals;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Process\PhpProcess;
use Symfony\Component\Security\Core\Authentication\AuthenticationManagerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Component\Security\Http\Firewall\ListenerInterface;

class WordpressListener implements ListenerInterface
{
    protected $securityContext;
    protected $authenticationManager;
    protected $documentRoot;
    protected $script;
    protected $logger;

    public function __construct(SecurityContextInterface $securityContext, AuthenticationManagerInterface $authenticationManager, $documentRoot, $script, LoggerInterface $logger = null)
    {
        $this->securityContext = $securityContext;
        $this->authenticationManager = $authenticationManager;
        $this->documentRoot = $documentRoot;
        $this->script = $script;
        $this->logger = $logger;
    }

    public function handle(GetResponseEvent $event)
    {
        if (null !== $this->securityContext->getToken()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->hasSession()) {
            throw new \RuntimeException('This authentication method requires a session.');
        }

        $cookies = array_intersect_key($request->cookies->all(), array_flip(array_filter(array_keys($request->cookies->all()), function ($input) {
            return (strpos($input, 'wordpress_logged_in_') === 0);
        })));

        $logger = $this->logger;

        if (empty($cookies)) {
            return;
        }

        if (null !== $this->logger) {
            $this->logger->debug('Found eligible cookies prefixed with wordpress_logged_in_');
        }

        $script = call_user_func($this->script, InjectRequestGlobals::toSubprocessGlobals($request), "\$user = wp_get_current_user(); echo json_encode(\$user);");
        $process = new PhpProcess('<?php '. $script, $this->documentRoot);
        $process->run();

        $output = $process->getOutput();
        $user = json_decode($output);

        // Attempt to load a WordPress user based on cookies for this site's domain.
        if (!$user || (isset($user->ID) && $user->ID === 0)) {
            return;
        }

        // Translate WordPress roles into Symfony Security component roles.
        $roles = array_map(function ($input) {
            return 'ROLE_WORDPRESS_'. strtoupper($input);
        }, $user->roles);
        $roles[] = 'ROLE_USER';

        // Generate token.
        $token = new WordpressUserToken($roles);
        $token->setUser($user->data->display_name);

        try {
            // Authorize token.
            $authToken = $this->authenticationManager->authenticate($token);
            $this->securityContext->setToken($authToken);

            return;
        } catch (AuthenticationException $failed) {
            // To deny the authentication clear the token. This will redirect to the login page.
            // Make sure to only clear your token, not those of other authentication listeners.
            $token = $this->securityContext->getToken();
            if ($token instanceof WordpressUserToken) {
                $this->securityContext->setToken(null);
            }

            // Deny authentication with a '403 Forbidden' HTTP response
            $response = new Response();
            $response->setStatusCode(403);
            $event->setResponse($response);

        }

        // By default deny authorization
        $response = new Response();
        $response->setStatusCode(403);
        $event->setResponse($response);
    }
}
