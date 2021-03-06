<?php
/**
 * @author Lukas Reschke
 * @copyright 2014-2015 Lukas Reschke lukas@owncloud.com
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace Tests\Settings\Controller;

use OC\Accounts\AccountManager;
use OC\Group\Group;
use OC\Group\Manager;
use OC\Settings\Controller\UsersController;
use OC\Settings\Mailer\NewUserMailHelper;
use OC\SubAdmin;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Config\IUserMountCache;
use OCP\Encryption\IEncryptionModule;
use OCP\Encryption\IManager;
use OCP\IAvatar;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Security\ISecureRandom;
use OC\User\User;
use Test\Util\User\Dummy;

/**
 * @group DB
 *
 * @package Tests\Settings\Controller
 */
class UsersControllerTest extends \Test\TestCase {

	/** @var IGroupManager|\PHPUnit_Framework_MockObject_MockObject */
	private $groupManager;
	/** @var IUserManager|\PHPUnit_Framework_MockObject_MockObject */
	private $userManager;
	/** @var IUserSession|\PHPUnit_Framework_MockObject_MockObject */
	private $userSession;
	/** @var IConfig|\PHPUnit_Framework_MockObject_MockObject */
	private $config;
	/** @var ILogger|\PHPUnit_Framework_MockObject_MockObject */
	private $logger;
	/** @var IMailer|\PHPUnit_Framework_MockObject_MockObject */
	private $mailer;
	/** @var IFactory|\PHPUnit_Framework_MockObject_MockObject */
	private $l10nFactory;
	/** @var IAppManager|\PHPUnit_Framework_MockObject_MockObject */
	private $appManager;
	/** @var IAvatarManager|\PHPUnit_Framework_MockObject_MockObject */
	private $avatarManager;
	/** @var IL10N|\PHPUnit_Framework_MockObject_MockObject */
	private $l;
	/** @var AccountManager | \PHPUnit_Framework_MockObject_MockObject */
	private $accountManager;
	/** @var ISecureRandom | \PHPUnit_Framework_MockObject_MockObject  */
	private $secureRandom;
	/** @var NewUserMailHelper|\PHPUnit_Framework_MockObject_MockObject */
	private $newUserMailHelper;
	/** @var  IJobList | \PHPUnit_Framework_MockObject_MockObject */
	private $jobList;
	/** @var \OC\Security\IdentityProof\Manager |\PHPUnit_Framework_MockObject_MockObject  */
	private $securityManager;
	/** @var  IManager | \PHPUnit_Framework_MockObject_MockObject */
	private $encryptionManager;
	/** @var  IEncryptionModule  | \PHPUnit_Framework_MockObject_MockObject */
	private $encryptionModule;

	protected function setUp() {
		parent::setUp();

		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(Manager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->config = $this->createMock(IConfig::class);
		$this->l = $this->createMock(IL10N::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->accountManager = $this->createMock(AccountManager::class);
		$this->securityManager = $this->getMockBuilder(\OC\Security\IdentityProof\Manager::class)->disableOriginalConstructor()->getMock();
		$this->jobList = $this->createMock(IJobList::class);
		$this->encryptionManager = $this->createMock(IManager::class);
		
		$this->l->method('t')
			->will($this->returnCallback(function ($text, $parameters = []) {
				return vsprintf($text, $parameters);
			}));

		$this->encryptionModule = $this->createMock(IEncryptionModule::class);
		$this->encryptionManager->expects($this->any())->method('getEncryptionModules')
			->willReturn(['encryptionModule' => ['callback' => function() { return $this->encryptionModule;}]]);

	}

	/**
	 * @param bool $isAdmin
	 * @return UsersController | \PHPUnit_Framework_MockObject_MockObject
	 */
	protected function getController($isAdmin = false, $mockedMethods = []) {
		if (empty($mockedMethods)) {
			return new UsersController(
				'settings',
				$this->createMock(IRequest::class),
				$this->userManager,
				$this->groupManager,
				$this->userSession,
				$this->config,
				$isAdmin,
				$this->l,
				$this->mailer,
				$this->l10nFactory,
				$this->appManager,
				$this->accountManager,
				$this->securityManager,
				$this->jobList,
				$this->encryptionManager
			);
		} else {
			return $this->getMockBuilder(UsersController::class)
				->setConstructorArgs(
					[
						'settings',
						$this->createMock(IRequest::class),
						$this->userManager,
						$this->groupManager,
						$this->userSession,
						$this->config,
						$isAdmin,
						$this->l,
						$this->mailer,
						$this->l10nFactory,
						$this->appManager,
						$this->accountManager,
						$this->securityManager,
						$this->jobList,
						$this->encryptionManager
					]
				)->setMethods($mockedMethods)->getMock();
		}
	}

	/**
	 * @dataProvider dataTestSetUserSettings
	 *
	 * @param string $email
	 * @param bool $validEmail
	 * @param $expectedStatus
	 */
	public function testSetUserSettings($email, $validEmail, $expectedStatus) {
		$controller = $this->getController(false, ['saveUserSettings']);
		$user = $this->createMock(IUser::class);

		$this->userSession->method('getUser')->willReturn($user);

		if (!empty($email) && $validEmail) {
			$this->mailer->expects($this->once())->method('validateMailAddress')
				->willReturn($validEmail);
		}

		$saveData = (!empty($email) && $validEmail) || empty($email);

		if ($saveData) {
			$this->accountManager->expects($this->once())
				->method('getUser')
				->with($user)
				->willReturn([
					AccountManager::PROPERTY_DISPLAYNAME =>
						[
							'value' => 'Display name',
							'scope' => AccountManager::VISIBILITY_CONTACTS_ONLY,
							'verified' => AccountManager::NOT_VERIFIED,
						],
					AccountManager::PROPERTY_ADDRESS =>
						[
							'value' => '',
							'scope' => AccountManager::VISIBILITY_PRIVATE,
							'verified' => AccountManager::NOT_VERIFIED,
						],
					AccountManager::PROPERTY_WEBSITE =>
						[
							'value' => '',
							'scope' => AccountManager::VISIBILITY_PRIVATE,
							'verified' => AccountManager::NOT_VERIFIED,
						],
					AccountManager::PROPERTY_EMAIL =>
						[
							'value' => '',
							'scope' => AccountManager::VISIBILITY_CONTACTS_ONLY,
							'verified' => AccountManager::NOT_VERIFIED,
						],
					AccountManager::PROPERTY_AVATAR =>
						[
							'scope' => AccountManager::VISIBILITY_CONTACTS_ONLY
						],
					AccountManager::PROPERTY_PHONE =>
						[
							'value' => '',
							'scope' => AccountManager::VISIBILITY_PRIVATE,
							'verified' => AccountManager::NOT_VERIFIED,
						],
					AccountManager::PROPERTY_TWITTER =>
						[
							'value' => '',
							'scope' => AccountManager::VISIBILITY_PRIVATE,
							'verified' => AccountManager::NOT_VERIFIED,
						],
				]);

			$controller->expects($this->once())->method('saveUserSettings');
		} else {
			$controller->expects($this->never())->method('saveUserSettings');
		}

		$result = $controller->setUserSettings(
			AccountManager::VISIBILITY_CONTACTS_ONLY,
			'displayName',
			AccountManager::VISIBILITY_CONTACTS_ONLY,
			'47658468',
			AccountManager::VISIBILITY_CONTACTS_ONLY,
			$email,
			AccountManager::VISIBILITY_CONTACTS_ONLY,
			'nextcloud.com',
			AccountManager::VISIBILITY_CONTACTS_ONLY,
			'street and city',
			AccountManager::VISIBILITY_CONTACTS_ONLY,
			'@nextclouders',
			AccountManager::VISIBILITY_CONTACTS_ONLY
		);

		$this->assertSame($expectedStatus, $result->getStatus());
	}

	public function dataTestSetUserSettings() {
		return [
			['', true, Http::STATUS_OK],
			['', false, Http::STATUS_OK],
			['example.com', false, Http::STATUS_UNPROCESSABLE_ENTITY],
			['john@example.com', true, Http::STATUS_OK],
		];
	}

	/**
	 * @dataProvider dataTestSaveUserSettings
	 *
	 * @param array $data
	 * @param string $oldEmailAddress
	 * @param string $oldDisplayName
	 */
	public function testSaveUserSettings($data,
										 $oldEmailAddress,
										 $oldDisplayName
	) {
		$controller = $this->getController();
		$user = $this->createMock(IUser::class);

		$user->method('getDisplayName')->willReturn($oldDisplayName);
		$user->method('getEMailAddress')->willReturn($oldEmailAddress);
		$user->method('canChangeDisplayName')->willReturn(true);

		if ($data[AccountManager::PROPERTY_EMAIL]['value'] === $oldEmailAddress ||
			($oldEmailAddress === null && $data[AccountManager::PROPERTY_EMAIL]['value'] === '')) {
			$user->expects($this->never())->method('setEMailAddress');
		} else {
			$user->expects($this->once())->method('setEMailAddress')
				->with($data[AccountManager::PROPERTY_EMAIL]['value'])
				->willReturn(true);
		}

		if ($data[AccountManager::PROPERTY_DISPLAYNAME]['value'] === $oldDisplayName ||
			($oldDisplayName === null && $data[AccountManager::PROPERTY_DISPLAYNAME]['value'] === '')) {
			$user->expects($this->never())->method('setDisplayName');
		} else {
			$user->expects($this->once())->method('setDisplayName')
				->with($data[AccountManager::PROPERTY_DISPLAYNAME]['value'])
				->willReturn(true);
		}

		$this->accountManager->expects($this->once())->method('updateUser')
			->with($user, $data);

		$this->invokePrivate($controller, 'saveUserSettings', [$user, $data]);
	}

	public function dataTestSaveUserSettings() {
		return [
			[
				[
					AccountManager::PROPERTY_EMAIL => ['value' => 'john@example.com'],
					AccountManager::PROPERTY_DISPLAYNAME => ['value' => 'john doe'],
				],
				'john@example.com',
				'john doe'
			],
			[
				[
					AccountManager::PROPERTY_EMAIL => ['value' => 'john@example.com'],
					AccountManager::PROPERTY_DISPLAYNAME => ['value' => 'john doe'],
				],
				'johnNew@example.com',
				'john New doe'
			],
			[
				[
					AccountManager::PROPERTY_EMAIL => ['value' => 'john@example.com'],
					AccountManager::PROPERTY_DISPLAYNAME => ['value' => 'john doe'],
				],
				'johnNew@example.com',
				'john doe'
			],
			[
				[
					AccountManager::PROPERTY_EMAIL => ['value' => 'john@example.com'],
					AccountManager::PROPERTY_DISPLAYNAME => ['value' => 'john doe'],
				],
				'john@example.com',
				'john New doe'
			],
			[
				[
					AccountManager::PROPERTY_EMAIL => ['value' => ''],
					AccountManager::PROPERTY_DISPLAYNAME => ['value' => 'john doe'],
				],
				null,
				'john New doe'
			],
			[
				[
					AccountManager::PROPERTY_EMAIL => ['value' => 'john@example.com'],
					AccountManager::PROPERTY_DISPLAYNAME => ['value' => 'john doe'],
				],
				'john@example.com',
				null
			],

		];
	}

	/**
	 * @dataProvider dataTestSaveUserSettingsException
	 *
	 * @param array $data
	 * @param string $oldEmailAddress
	 * @param string $oldDisplayName
	 * @param bool $setDisplayNameResult
	 * @param bool $canChangeEmail
	 *
	 * @expectedException \OC\ForbiddenException
	 */
	public function testSaveUserSettingsException($data,
												  $oldEmailAddress,
												  $oldDisplayName,
												  $setDisplayNameResult,
												  $canChangeEmail
	) {
		$controller = $this->getController();
		$user = $this->createMock(IUser::class);

		$user->method('getDisplayName')->willReturn($oldDisplayName);
		$user->method('getEMailAddress')->willReturn($oldEmailAddress);

		if ($data[AccountManager::PROPERTY_EMAIL]['value'] !== $oldEmailAddress) {
			$user->method('canChangeDisplayName')
				->willReturn($canChangeEmail);
		}

		if ($data[AccountManager::PROPERTY_DISPLAYNAME]['value'] !== $oldDisplayName) {
			$user->method('setDisplayName')
				->with($data[AccountManager::PROPERTY_DISPLAYNAME]['value'])
				->willReturn($setDisplayNameResult);
		}

		$this->invokePrivate($controller, 'saveUserSettings', [$user, $data]);
	}


	public function dataTestSaveUserSettingsException() {
		return [
			[
				[
					AccountManager::PROPERTY_EMAIL => ['value' => 'john@example.com'],
					AccountManager::PROPERTY_DISPLAYNAME => ['value' => 'john doe'],
				],
				'johnNew@example.com',
				'john New doe',
				true,
				false
			],
			[
				[
					AccountManager::PROPERTY_EMAIL => ['value' => 'john@example.com'],
					AccountManager::PROPERTY_DISPLAYNAME => ['value' => 'john doe'],
				],
				'johnNew@example.com',
				'john New doe',
				false,
				true
			],
			[
				[
					AccountManager::PROPERTY_EMAIL => ['value' => 'john@example.com'],
					AccountManager::PROPERTY_DISPLAYNAME => ['value' => 'john doe'],
				],
				'johnNew@example.com',
				'john New doe',
				false,
				false
			],

		];
	}

	/**
	 * @param string $account
	 * @param string $type
	 * @param array $dataBefore
	 * @param array $expectedData
	 *
	 * @dataProvider dataTestGetVerificationCode
	 */
	public function testGetVerificationCode($account, $type, $dataBefore, $expectedData, $onlyVerificationCode) {

		$message = 'Use my Federated Cloud ID to share with me: user@nextcloud.com';
		$signature = 'theSignature';

		$code = $message . ' ' . $signature;
		if($type === AccountManager::PROPERTY_TWITTER) {
			$code = $message . ' ' . md5($signature);
		}

		$controller = $this->getController(false, ['signMessage', 'getCurrentTime']);

		$user = $this->createMock(IUser::class);
		$this->userSession->expects($this->once())->method('getUser')->willReturn($user);
		$this->accountManager->expects($this->once())->method('getUser')->with($user)->willReturn($dataBefore);
		$user->expects($this->any())->method('getCloudId')->willReturn('user@nextcloud.com');
		$user->expects($this->any())->method('getUID')->willReturn('uid');
		$controller->expects($this->once())->method('signMessage')->with($user, $message)->willReturn($signature);
		$controller->expects($this->any())->method('getCurrentTime')->willReturn(1234567);

		if ($onlyVerificationCode === false) {
			$this->accountManager->expects($this->once())->method('updateUser')->with($user, $expectedData);
			$this->jobList->expects($this->once())->method('add')
				->with('OC\Settings\BackgroundJobs\VerifyUserData',
					[
						'verificationCode' => $code,
						'data' => $dataBefore[$type]['value'],
						'type' => $type,
						'uid' => 'uid',
						'try' => 0,
						'lastRun' => 1234567
					]);
		}

		$result = $controller->getVerificationCode($account, $onlyVerificationCode);

		$data = $result->getData();
		$this->assertSame(Http::STATUS_OK, $result->getStatus());
		$this->assertSame($code, $data['code']);
	}

	public function dataTestGetVerificationCode() {

		$accountDataBefore = [
			AccountManager::PROPERTY_WEBSITE => ['value' => 'https://nextcloud.com', 'verified' => AccountManager::NOT_VERIFIED],
			AccountManager::PROPERTY_TWITTER => ['value' => '@nextclouders', 'verified' => AccountManager::NOT_VERIFIED, 'signature' => 'theSignature'],
		];

		$accountDataAfterWebsite = [
			AccountManager::PROPERTY_WEBSITE => ['value' => 'https://nextcloud.com', 'verified' => AccountManager::VERIFICATION_IN_PROGRESS, 'signature' => 'theSignature'],
			AccountManager::PROPERTY_TWITTER => ['value' => '@nextclouders', 'verified' => AccountManager::NOT_VERIFIED, 'signature' => 'theSignature'],
		];

		$accountDataAfterTwitter = [
			AccountManager::PROPERTY_WEBSITE => ['value' => 'https://nextcloud.com', 'verified' => AccountManager::NOT_VERIFIED],
			AccountManager::PROPERTY_TWITTER => ['value' => '@nextclouders', 'verified' => AccountManager::VERIFICATION_IN_PROGRESS, 'signature' => 'theSignature'],
		];

		return [
			['verify-twitter', AccountManager::PROPERTY_TWITTER, $accountDataBefore, $accountDataAfterTwitter, false],
			['verify-website', AccountManager::PROPERTY_WEBSITE, $accountDataBefore, $accountDataAfterWebsite, false],
			['verify-twitter', AccountManager::PROPERTY_TWITTER, $accountDataBefore, $accountDataAfterTwitter, true],
			['verify-website', AccountManager::PROPERTY_WEBSITE, $accountDataBefore, $accountDataAfterWebsite, true],
		];
	}

	/**
	 * test get verification code in case no valid user was given
	 */
	public function testGetVerificationCodeInvalidUser() {

		$controller = $this->getController();
		$this->userSession->expects($this->once())->method('getUser')->willReturn(null);
		$result = $controller->getVerificationCode('account', false);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
	}

}
