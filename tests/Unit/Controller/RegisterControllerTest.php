<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Registration\Tests\Unit\Controller;

use OCA\Registration\Controller\RegisterController;
use OCA\Registration\Db\Registration;
use OCA\Registration\Service\LoginFlowService;
use OCA\Registration\Service\MailService;
use OCA\Registration\Service\RegistrationException;
use OCA\Registration\Service\RegistrationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use ChristophWurst\Nextcloud\Testing\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class RegisterControllerTest extends TestCase {

	/** @var IRequest */
	private $request;
	/** @var IL10N|MockObject */
	private $l10n;
	/** @var IURLGenerator|MockObject */
	private $urlGenerator;
	/** @var RegistrationService|MockObject */
	private $registrationService;
	/** @var LoginFlowService|MockObject */
	private $loginFlowService;
	/** @var MailService|MockObject */
	private $mailService;

	public function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->registrationService = $this->createMock(RegistrationService::class);
		$this->loginFlowService = $this->createMock(LoginFlowService::class);
		$this->mailService = $this->createMock(MailService::class);

		$this->l10n->expects($this->any())
			->method('t')
			->willReturnCallback(function ($text, $parameters = []) {
				return vsprintf($text, $parameters);
			});
	}

	/**
	 * @param string[] $methods
	 * @return RegisterController|MockObject
	 */
	protected function getController(array $methods = []) {
		if (empty($methods)) {
			return new RegisterController(
				'registration',
				$this->request,
				$this->l10n,
				$this->urlGenerator,
				$this->registrationService,
				$this->loginFlowService,
				$this->mailService
			);
		}

		return $this->getMockBuilder(RegisterController::class)
			->onlyMethods($methods)
			->setConstructorArgs([
				'registration',
				$this->request,
				$this->l10n,
				$this->urlGenerator,
				$this->registrationService,
				$this->loginFlowService,
				$this->mailService,
			])
			->getMock();
	}

	public function dataShowEmailForm(): array {
		return [
			['', ''],
			['test@example.tld', 'Registration is only allowed for the following domains: nextcloud.com'],
		];
	}

	/**
	 * @dataProvider dataShowEmailForm
	 * @param string $email
	 * @param string $message
	 */
	public function testShowEmailForm(string $email, string $message): void {
		$controller = $this->getController();
		$response = $controller->showEmailForm($email, $message);

		self::assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
		self::assertSame('form/email', $response->getTemplateName());

		self::assertSame([
			'email' => $email,
			'message' => $message,
		], $response->getParams());
	}

	public function testSubmitEmailForm(): void {
		$email = 'nextcloud@example.tld';

		$this->registrationService
			->method('getRegistrationForEmail')
			->with($email)
			->willThrowException(new DoesNotExistException($email));

		$registration = Registration::fromParams([
			'clientSecret' => 'clientSecret',
		]);

		$this->registrationService
			->expects($this->once())
			->method('validateEmail')
			->with($email);
		$this->registrationService
			->expects($this->once())
			->method('createRegistration')
			->with($email)
			->willReturn($registration);

		$this->mailService
			->expects($this->once())
			->method('sendTokenByMail')
			->with($registration);

		$this->urlGenerator
			->method('linkToRoute')
			->willReturnCallback(function () {
				return json_encode(func_get_args());
			});

		$controller = $this->getController();
		$response = $controller->submitEmailForm($email);

		self::assertInstanceOf(RedirectResponse::class, $response);
		/** @var RedirectResponse $response */
		self::assertSame('["registration.register.showVerificationForm",{"secret":"clientSecret"}]', $response->getRedirectURL());
	}

	public function testSubmitEmailFormInvalidEmail(): void {
		$email = 'nextcloud@example.tld';

		$this->registrationService
			->method('getRegistrationForEmail')
			->with($email)
			->willThrowException(new DoesNotExistException($email));

		$this->registrationService
			->expects($this->once())
			->method('validateEmail')
			->with($email)
			->willThrowException(new RegistrationException('Invalid email'));
		$this->registrationService
			->expects($this->never())
			->method('createRegistration');

		$controller = $this->getController([
			'showEmailForm',
		]);

		$response = $this->createMock(TemplateResponse::class);
		$controller->expects($this->once())
			->method('showEmailForm')
			->with($email, 'Invalid email')
			->willReturn($response);

		self::assertSame($response, $controller->submitEmailForm($email));
	}

	public function testSubmitEmailFormErrorSendingEmail(): void {
		$email = 'nextcloud@example.tld';

		$this->registrationService
			->method('getRegistrationForEmail')
			->with($email)
			->willThrowException(new DoesNotExistException($email));

		$registration = Registration::fromParams([
			'clientSecret' => 'clientSecret',
		]);

		$this->registrationService
			->expects($this->once())
			->method('validateEmail')
			->with($email);
		$this->registrationService
			->expects($this->once())
			->method('createRegistration')
			->with($email)
			->willReturn($registration);

		$this->mailService
			->expects($this->once())
			->method('sendTokenByMail')
			->with($registration)
			->willThrowException(new RegistrationException('Error sending email'));

		$controller = $this->getController([
			'showEmailForm',
		]);

		$response = $this->createMock(TemplateResponse::class);
		$controller->expects($this->once())
			->method('showEmailForm')
			->with($email, 'Error sending email')
			->willReturn($response);

		self::assertSame($response, $controller->submitEmailForm($email));
	}

	public function testSubmitEmailFormResendPendingRequest(): void {
		$email = 'nextcloud@example.tld';

		$registration = Registration::fromParams([
			'clientSecret' => 'clientSecret',
		]);

		$this->registrationService
			->method('getRegistrationForEmail')
			->with($email)
			->willReturn($registration);

		$this->registrationService
			->expects($this->once())
			->method('generateNewToken')
			->with($registration);

		$this->mailService
			->expects($this->once())
			->method('sendTokenByMail')
			->with($registration);

		$this->urlGenerator
			->method('linkToRoute')
			->willReturnCallback(function () {
				return json_encode(func_get_args());
			});

		$controller = $this->getController();
		$response = $controller->submitEmailForm($email);
		self::assertInstanceOf(RedirectResponse::class, $response);
		/** @var RedirectResponse $response */
		self::assertSame('["registration.register.showVerificationForm",{"secret":"clientSecret"}]', $response->getRedirectURL());
	}

	public function dataShowVerificationForm(): array {
		return [
			[''],
			['The entered verification code is wrong'],
		];
	}

	/**
	 * @dataProvider dataShowVerificationForm
	 * @param string $message
	 */
	public function testShowVerificationForm(string $message): void {
		$secret = '123456789';

		$this->registrationService
			->expects($this->once())
			->method('getRegistrationForSecret')
			->with($secret);

		$controller = $this->getController();
		$response = $controller->showVerificationForm($secret, $message);

		self::assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
		self::assertSame('form/verification', $response->getTemplateName());

		self::assertSame([
			'message' => $message,
		], $response->getParams());
	}

	public function testShowVerificationFormInvalidSecret(): void {
		$secret = '123456789';
		$message = '';

		$this->registrationService
			->expects($this->once())
			->method('getRegistrationForSecret')
			->with($secret)
			->willThrowException(new DoesNotExistException('Not found'));

		$response = $this->createMock(TemplateResponse::class);
		$controller = $this->getController([
			'validateSecretAndTokenErrorPage'
		]);

		$controller->expects($this->once())
			->method('validateSecretAndTokenErrorPage')
			->willReturn($response);

		self::assertSame($response, $controller->showVerificationForm($secret, $message));
	}

	public function testSubmitVerificationForm(): void {
		$secret = '123456789';
		$token = 'abcdefghi';

		$registration = Registration::fromParams([
			'clientSecret' => $secret,
			'token' => $token,
		]);

		$this->registrationService
			->expects($this->once())
			->method('getRegistrationForSecret')
			->with($secret)
			->willReturn($registration);

		$this->urlGenerator
			->method('linkToRoute')
			->willReturnCallback(function () {
				return json_encode(func_get_args());
			});

		$controller = $this->getController();
		$response = $controller->submitVerificationForm($secret, $token);
		self::assertInstanceOf(RedirectResponse::class, $response);
		/** @var RedirectResponse $response */
		self::assertSame('["registration.register.showUserForm",{"secret":"123456789","token":"abcdefghi"}]', $response->getRedirectURL());
	}

	public function testSubmitVerificationFormInvalidToken(): void {
		$secret = '123456789';
		$token = 'abcdefghi';

		$registration = Registration::fromParams([
			'clientSecret' => $secret,
			'token' => 'zyxwvu',
		]);

		$this->registrationService
			->expects($this->once())
			->method('getRegistrationForSecret')
			->with($secret)
			->willReturn($registration);

		$response = $this->createMock(TemplateResponse::class);
		$controller = $this->getController([
			'showVerificationForm',
		]);
		$controller->expects($this->once())
			->method('showVerificationForm')
			->with($secret, 'The entered verification code is wrong')
			->willReturn($response);

		self::assertSame($response, $controller->submitVerificationForm($secret, $token));
	}

	public function testSubmitVerificationFormInvalidSecret(): void {
		$secret = '123456789';
		$token = 'abcdefghi';

		$registration = Registration::fromParams([
			'clientSecret' => $secret,
			'token' => $token,
		]);

		$this->registrationService
			->expects($this->once())
			->method('getRegistrationForSecret')
			->with($secret)
			->willThrowException(new DoesNotExistException('Invalid secret'));

		$response = $this->createMock(TemplateResponse::class);
		$controller = $this->getController([
			'validateSecretAndTokenErrorPage',
		]);
		$controller->expects($this->once())
			->method('validateSecretAndTokenErrorPage')
			->willReturn($response);

		self::assertSame($response, $controller->submitVerificationForm($secret, $token));
	}

	public function dataShowUserForm(): array {
		return [
			['', ''],
			['tester', ''],
			['', 'Unable to create user, there are problems with the user backend.'],
		];
	}

	/**
	 * @dataProvider dataShowUserForm
	 * @param string $username
	 * @param string $message
	 */
	public function testShowUserForm(string $username, string $message): void {
		$secret = '123456789';
		$token = 'abcdefghi';
		$email = 'nextcloud@example.tld';

		$registration = Registration::fromParams([
			'email' => 'nextcloud@example.tld',
		]);

		$controller = $this->getController([
			'validateSecretAndToken'
		]);

		$controller->expects($this->once())
			->method('validateSecretAndToken')
			->willReturn($registration);

		$response = $controller->showUserForm($secret, $token, $username, $message);

		self::assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
		self::assertSame('form/user', $response->getTemplateName());

		self::assertSame([
			'email' => $email,
			'username' => $username,
			'message' => $message,
		], $response->getParams());
	}

	public function testShowUserFormInvalidSecretAndToken(): void {
		$secret = '123456789';
		$token = 'abcdefghi';

		$controller = $this->getController([
			'validateSecretAndToken',
			'validateSecretAndTokenErrorPage',
		]);

		$controller->expects($this->once())
			->method('validateSecretAndToken')
			->willThrowException(new RegistrationException('Invalid secret or token'));

		$response = $this->createMock(TemplateResponse::class);
		$controller->expects($this->once())
			->method('validateSecretAndTokenErrorPage')
			->willReturn($response);

		self::assertSame($response, $controller->showUserForm($secret, $token));
	}
}
