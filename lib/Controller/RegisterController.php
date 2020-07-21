<?php

declare(strict_types=1);

/**
 * ownCloud - registration
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pellaeon Lin <pellaeon@hs.ntnu.edu.tw>
 * @author Julius Härtl <jus@bitgrid.net>
 * @author 2020 Joas Schilling <coding@schilljs.com>
 * @copyright Pellaeon Lin 2014
 */

namespace OCA\Registration\Controller;

use Exception;
use OCA\Registration\Db\Registration;
use OCA\Registration\Service\LoginFlowService;
use OCA\Registration\Service\MailService;
use OCA\Registration\Service\RegistrationException;
use OCA\Registration\Service\RegistrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\RedirectToDefaultAppResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StandaloneTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

class RegisterController extends Controller {

	/** @var IL10N */
	private $l10n;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var RegistrationService */
	private $registrationService;
	/** @var MailService */
	private $mailService;
	/** @var LoginFlowService */
	private $loginFlowService;

	public function __construct(
		string $appName,
		IRequest $request,
		IL10N $l10n,
		IURLGenerator $urlGenerator,
		RegistrationService $registrationService,
		LoginFlowService $loginFlowService,
		MailService $mailService
	) {
		parent::__construct($appName, $request);
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
		$this->registrationService = $registrationService;
		$this->loginFlowService = $loginFlowService;
		$this->mailService = $mailService;
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $email
	 * @param string $message
	 * @return TemplateResponse
	 */
	public function showEmailForm(string $email = '', string $message = ''): TemplateResponse {
		$params = [
			'email' => $email,
			'message' => $message,
		];
		return new TemplateResponse('registration', 'form/email', $params, 'guest');
	}

	/**
	 * @PublicPage
	 * @AnonRateThrottle(limit=5, period=1)
	 *
	 * @param string $email
	 * @return TemplateResponse
	 */
	public function submitEmailForm(string $email): Response {
		try {
			// Registration already in progress, update token and continue with verification
			$registration = $this->registrationService->getRegistrationForEmail($email);
			$this->registrationService->generateNewToken($registration);
		} catch (DoesNotExistException $e) {
			// No registration in progress
			try {
				$this->registrationService->validateEmail($email);
				$registration = $this->registrationService->createRegistration($email);
			} catch (RegistrationException $e) {
				return $this->showEmailForm($email, $e->getMessage());
			}
		}

		try {
			$this->mailService->sendTokenByMail($registration);
		} catch (RegistrationException $e) {
			return $this->showEmailForm($email, $e->getMessage());
		}

		return new RedirectResponse(
			$this->urlGenerator->linkToRoute(
				'registration.register.showVerificationForm',
				['secret' => $registration->getClientSecret()]
			)
		);
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $secret
	 * @param string $message
	 * @return TemplateResponse
	 */
	public function showVerificationForm(string $secret, string $message = ''): TemplateResponse {
		try {
			$this->registrationService->getRegistrationForSecret($secret);
		} catch (DoesNotExistException $e) {
			return $this->validateSecretAndTokenErrorPage();
		}

		return new TemplateResponse('registration', 'form/verification', [
			'message' => $message,
		], 'guest');
	}

	/**
	 * @PublicPage
	 * @AnonRateThrottle(limit=5, period=1)
	 *
	 * @param string $secret
	 * @param string $token
	 * @return Response
	 */
	public function submitVerificationForm(string $secret, string $token): Response {
		try {
			$registration = $this->registrationService->getRegistrationForSecret($secret);

			if ($registration->getToken() !== $token) {
				return $this->showVerificationForm(
					$secret,
					$this->l10n->t('The entered verification code is wrong')
				);
			}
		} catch (DoesNotExistException $e) {
			return $this->validateSecretAndTokenErrorPage();
		}

		return new RedirectResponse(
			$this->urlGenerator->linkToRoute(
				'registration.register.showUserForm',
				[
					'secret' => $secret,
					'token' => $token,
				]
			)
		);
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $secret
	 * @param string $token
	 * @param string $username
	 * @param string $message
	 * @return TemplateResponse
	 */
	public function showUserForm(string $secret, string $token, string $username = '', string $message = ''): TemplateResponse {
		try {
			$registration = $this->validateSecretAndToken($secret, $token);
		} catch (RegistrationException $e) {
			return $this->validateSecretAndTokenErrorPage();
		}

		return new TemplateResponse('registration', 'form/user', [
			'email' => $registration->getEmail(),
			'username' => $username,
			'message' => $message,
		], 'guest');
	}

	/**
	 * @PublicPage
	 * @UseSession
	 *
	 * @param string $secret
	 * @param string $token
	 * @param string $username
	 * @param string $password
	 * @return RedirectResponse|TemplateResponse
	 */
	public function submitUserForm(string $secret, string $token, string $username, string $password): Response {
		try {
			$registration = $this->validateSecretAndToken($secret, $token);
		} catch (RegistrationException $e) {
			return $this->validateSecretAndTokenErrorPage();
		}

		try {
			$user = $this->registrationService->createAccount($registration, $username, $password);
		} catch (Exception $exception) {
			return $this->showUserForm($secret, $token, $username, $exception->getMessage());
		}

		if ($user->isEnabled()) {
			$this->registrationService->loginUser($user->getUID(), $user->getUID(), $password);

			if ($this->loginFlowService->isUsingLoginFlow(2)) {
				$response = $this->loginFlowService->tryLoginFlowV2($user);
				if ($response instanceof Response) {
					return $response;
				}
			}

			if ($this->loginFlowService->isUsingLoginFlow(1)) {
				$response = $this->loginFlowService->tryLoginFlowV1();
				if ($response instanceof Response && $response->getStatus() === Http::STATUS_SEE_OTHER) {
					return $response;
				}
			}

			return new RedirectToDefaultAppResponse();
		}

		// warn the user their account needs admin validation
		return new StandaloneTemplateResponse('registration', 'approval-required', [], 'guest');
	}

	/**
	 * @param string $secret
	 * @param string $token
	 * @return Registration
	 * @throws RegistrationException
	 */
	protected function validateSecretAndToken(string $secret, string $token): Registration {
		try {
			$registration = $this->registrationService->getRegistrationForSecret($secret);
		} catch (DoesNotExistException $e) {
			throw new RegistrationException('Invalid secret');
		}

		if ($registration->getToken() !== $token) {
			throw new RegistrationException('Invalid token');
		}

		return $registration;
	}

	protected function validateSecretAndTokenErrorPage(): TemplateResponse {
		return new TemplateResponse('core', 'error', [
			'errors' => [
				$this->l10n->t('The verification failed.'),
			],
		], 'error');
	}
}
