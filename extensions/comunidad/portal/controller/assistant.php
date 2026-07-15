<?php
/**
 *
 * Portal Comunitario — assistant AJAX endpoint.
 *
 * Single endpoint that runs any of the five assistant actions. The UI
 * surfaces (posting editor, PM view) POST `{action, text}` plus the
 * phpBB form token under `form_token`, and renders the returned
 * `text` back into the editor / reply box.
 *
 * Response shape (always JSON):
 *   ok  : { ok: true,  text: string, remaining: int }
 *   err : { ok: false, error: string, remaining?: int }
 *
 * CSRF is enforced via check_form_key with trigger_error disabled so
 * a missing/bad token returns a 403 JSON body instead of an HTML
 * error page.
 *
 * @package comunidad\portal
 */
namespace comunidad\portal\controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use comunidad\portal\service\assistant\AssistantService;

class assistant
{
	/** @var \phpbb\config\config */
	protected $config;
	/** @var \phpbb\request\request */
	protected $request;
	/** @var \phpbb\user */
	protected $user;
	/** @var \phpbb\auth\auth */
	protected $auth;
	/** @var AssistantService */
	protected $assistant;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\request\request $request,
		\phpbb\user $user,
		\phpbb\auth\auth $auth,
		AssistantService $assistant
	) {
		$this->config    = $config;
		$this->request   = $request;
		$this->user      = $user;
		$this->auth      = $auth;
		$this->assistant = $assistant;
	}

	public function run(): JsonResponse
	{
		if (!$this->request->is_ajax()) {
			return $this->error('Solo se permiten solicitudes AJAX.', 400);
		}

		if ($this->user->data['user_id'] == ANONYMOUS) {
			return $this->error('Debes iniciar sesión para usar el asistente.', 401);
		}

		if (!$this->auth->acl_get('u_')) {
			return $this->error('No tienes permiso para usar el asistente.', 403);
		}

		if (!check_form_key('portal_assistant', -1, false)) {
			return $this->error('Token de seguridad inválido. Recarga la página.', 403);
		}

		$action = trim((string) $this->request->variable('action', '', true));
		$text   = (string) $this->request->variable('text', '', true);

		try {
			$result = $this->assistant->run($action, $text, (int) $this->user->data['user_id']);
		}
		catch (\Throwable $e)
		{
			return $this->error($e->getMessage(), 502);
		}

		if (!empty($result['ok'])) {
			return new JsonResponse([
				'ok'        => true,
				'text'      => (string) $result['text'],
				'remaining' => (int) ($result['remaining'] ?? 0),
			]);
		}

		return new JsonResponse([
			'ok'        => false,
			'error'     => (string) ($result['error'] ?? 'Error desconocido.'),
			'remaining' => isset($result['remaining']) ? (int) $result['remaining'] : null,
		], 422);
	}

	private function error(string $message, int $status): JsonResponse
	{
		return new JsonResponse([
			'ok'    => false,
			'error' => $message,
		], $status);
	}
}
