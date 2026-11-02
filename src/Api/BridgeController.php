<?php

namespace ErnestDefoe\MillwrightBridge\Api;

use ErnestDefoe\MillwrightBridge\Bridge;
use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET: the check. POST {action: stage|upgrade}: build Flarum 2, or swap it in.
 * Admins only, every time.
 */
class BridgeController implements RequestHandlerInterface
{
    public function __construct(private Paths $paths, private TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $bridge = new Bridge($this->paths, $this->translator);

        if ($request->getMethod() === 'GET') {
            return new JsonResponse($bridge->check());
        }

        $action = (string) (((array) $request->getParsedBody())['action'] ?? '');

        if ($action === 'upgrade') {
            $result = $bridge->upgrade();

            /*
             * 🚨 Answered and ended HERE. Flarum 1's pipeline would carry on
             * after this handler (sessions, middleware) and load its classes
             * from a vendor/ that is now Flarum 2's. The work is done; nothing
             * after it may touch the framework.
             */
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            http_response_code(200);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo json_encode($result);
            exit;
        }

        return new JsonResponse(match ($action) {
            'stage' => $bridge->stage(),
            default => ['ok' => false, 'error' => $bridge->t('unknown_action')],
        });
    }
}
