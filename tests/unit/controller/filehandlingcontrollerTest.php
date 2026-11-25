<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_Texteditor\Tests\Controller;

use OC\HintException;
use OC\User\Session;
use OCA\Files_Texteditor\Controller\FileHandlingController;
use OCP\Constants;
use OCP\Files\ForbiddenException;
use OCP\Files\Storage\IStorage;
use OCP\Files\Storage\IPersistentLockingStorage;
use OCP\Lock\Persistent\ILock;
use OCP\Lock\LockedException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\ISession;
use Test\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Firebase\JWT\JWT;

interface IPersistentLockingStorageTest extends IPersistentLockingStorage, IStorage {
}

class FileHandlingControllerTest extends TestCase {
	/** @var FileHandlingController */
	protected $controller;

	/** @var string */
	protected $appName;

	/** @var \OCP\IRequest|\PHPUnit\Framework\MockObject\MockObject */
	protected $requestMock;

	/** @var \OCP\IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10nMock;

	/** @var \OCP\ILogger|\PHPUnit\Framework\MockObject\MockObject */
	private $loggerMock;

	/** @var \OCP\Share\IManager|\PHPUnit\Framework\MockObject\MockObject */
	private $shareManagerMock;

	/** @var \OCP\IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSessionMock;

	/** @var \OCP\Files\IRootFolder|\PHPUnit\Framework\MockObject\MockObject */
	private $rootMock;

	/** @var \OCP\Files\File|\PHPUnit\Framework\MockObject\MockObject */
	private $fileMock;

	/** @var IStorage|\PHPUnit\Framework\MockObject\MockObject */
	private $fileStorageMock;

	/** @var \OCP\IUser|\PHPUnit\Framework\MockObject\MockObject */
	private $userMock;

	/** @var \OCP\Share\IShare|\PHPUnit\Framework\MockObject\MockObject */
	private $shareMock;

	/** @var \OCP\IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $configMock;

	/** Instanzgeheimnis der Tests (config.php „secret“) */
	private const string INSTANCE_SECRET = 'instanz-geheimnis-der-tests';

	/** Früher fest im Code eingetragener, öffentlich bekannter Schlüssel */
	private const string OLD_PUBLIC_KEY = 'jwt-secret-key-for-files_texteditor';

	public function setUp(): void {
		parent::setUp();
		$this->appName = 'files_texteditor';
		$this->requestMock = $this->getMockBuilder('OCP\IRequest')
			->disableOriginalConstructor()
			->getMock();
		$this->l10nMock = $this->getMockBuilder('OCP\IL10N')
			->disableOriginalConstructor()
			->getMock();
		$this->loggerMock = $this->getMockBuilder('OCP\ILogger')
			->disableOriginalConstructor()
			->getMock();
		$this->shareManagerMock = $this->getMockBuilder('OCP\Share\IManager')
			->disableOriginalConstructor()
			->getMock();
		$this->userSessionMock = $this->getMockBuilder('OCP\IUserSession')
			->disableOriginalConstructor()
			->getMock();
		$this->rootMock = $this->getMockBuilder('OCP\Files\IRootFolder')
			->disableOriginalConstructor()
			->getMock();
		$this->fileStorageMock = $this->getMockBuilder(IStorage::class)
			->disableOriginalConstructor()
			->getMock();
		$this->fileMock = $this->getMockBuilder(File::class)
			->disableOriginalConstructor()
			->getMock();
		$this->userMock = $this->getMockBuilder('OCP\IUser')
			->disableOriginalConstructor()
			->getMock();
		$this->shareMock = $this->getMockBuilder('OCP\Share\IShare')
			->disableOriginalConstructor()
			->getMock();
		$this->configMock = $this->createMock('OCP\IConfig');
		$this->configMock->method('getSystemValue')->willReturnCallback(
			static fn ($key, $default = '') => $key === 'secret' ? self::INSTANCE_SECRET : $default
		);

		$this->l10nMock->expects($this->any())->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return \vsprintf($text, $parameters);
		});

		$this->controller = new FileHandlingController(
			$this->appName,
			$this->requestMock,
			$this->l10nMock,
			$this->loggerMock,
			$this->shareManagerMock,
			$this->userSessionMock,
			$this->rootMock,
			$this->configMock
		);
	}

	/**
	 * @dataProvider dataTestLoad
	 *
	 * @param string $filename
	 * @param string|boolean $fileContent
	 * @param integer $expectedStatus
	 * @param string $expectedMessage
	 */
	public function testLoad($filename, $fileContent, $expectedStatus, $expectedMessage) {
		$this->fileMock->expects($this->any())
			->method('getContent')
			->willReturn($fileContent);

		$this->fileMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($this->fileStorageMock);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$this->userMock->expects($this->any())
			->method('getUID')
			->willReturn('admin');

		$this->userSessionMock->expects($this->any())
			->method('getUser')
			->willReturn($this->userMock);

		$result = $this->controller->load('/', $filename);
		$data = $result->getData();
		$status = $result->getStatus();
		$this->assertSame($status, $expectedStatus);
		if ($status === 200) {
			$this->assertArrayHasKey('filecontents', $data);
			$this->assertArrayHasKey('writeable', $data);
			$this->assertArrayHasKey('locked', $data);
			$this->assertArrayHasKey('mime', $data);
			$this->assertArrayHasKey('mtime', $data);
			$this->assertSame($data['filecontents'], $fileContent);
			$this->assertSame($data['writeable'], true);
			$this->assertSame($data['locked'], null);
		} else {
			$this->assertArrayHasKey('message', $data);
			$this->assertSame($expectedMessage, $data['message']);
		}
	}

	public function dataTestLoad() {
		return [
			['test.txt', 'file content', 200, ''],
			['test.txt', '', 200, ''],
			['test.txt', '0', 200, ''],
			['', 'file content', 400, 'Invalid file path supplied.'],
			['test.txt', false, 400, 'Cannot read the file.'],
		];
	}

	public function dataLoadExceptionWithException() {
		return [
			[new \Exception(), 'An internal server error occurred.'],
			[new HintException('error message', 'test exception'), 'test exception'],
			[new ForbiddenException('firewall', false), 'firewall'],
			[new LockedException('secret/path/https://github.com/owncloud/files_texteditor/pull/96'), 'The file is locked.'],
		];
	}

	/**
	 * @dataProvider dataLoadExceptionWithException
	 * @param \Exception $exception
	 * @param string $expectedMessage
	 */
	public function testLoadExceptionWithException(\Exception $exception, $expectedMessage) {
		$this->fileMock->expects($this->any())
			->method('getContent')
			->willReturnCallback(function () use ($exception) {
				throw $exception;
			});

		$this->fileMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($this->fileStorageMock);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$this->userMock->expects($this->any())
			->method('getUID')
			->willReturn('admin');

		$this->userSessionMock->expects($this->any())
			->method('getUser')
			->willReturn($this->userMock);

		$result = $this->controller->load('/', 'test.txt');
		$data = $result->getData();

		$this->assertSame(400, $result->getStatus());
		$this->assertArrayHasKey('message', $data);
		$this->assertSame($expectedMessage, $data['message']);
	}

	/**
	 * @dataProvider dataLoadExceptionWithException
	 * @param \Exception $exception
	 * @param string $expectedMessage
	 */
	public function testSaveExceptionWithException(\Exception $exception, $expectedMessage) {
		$this->fileMock->expects($this->any())
			->method('putContent')
			->willReturnCallback(function () use ($exception) {
				throw $exception;
			});

		$this->fileMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($this->fileStorageMock);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$this->userMock->expects($this->any())
			->method('getUID')
			->willReturn('admin');

		$this->userSessionMock->expects($this->any())
			->method('getUser')
			->willReturn($this->userMock);

		$this->fileMock->expects($this->any())
			->method('getMTime')
			->willReturn(42);

		$result = $this->controller->save('/test.txt', 'content', 42);
		$data = $result->getData();

		$this->assertSame(400, $result->getStatus());
		$this->assertArrayHasKey('message', $data);
		$this->assertSame($expectedMessage, $data['message']);
	}

	/**
	 * @dataProvider dataTestSave
	 *
	 * @param $path
	 * @param $fileContents
	 * @param $mTime
	 * @param $fileMTime
	 * @param $isUpdatable
	 * @param $expectedStatus
	 * @param $expectedMessage
	 */
	public function testSave($path, $fileContents, $mTime, $fileMTime, $isUpdatable, $expectedStatus, $expectedMessage) {
		if ($isUpdatable) {
			$permissions = Constants::PERMISSION_ALL;
		} else {
			$permissions = Constants::PERMISSION_READ;
		}
		$this->fileMock->expects($this->any())
			->method('getPermissions')
			->willReturn($permissions);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($this->fileStorageMock);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$this->userMock->expects($this->any())
			->method('getUID')
			->willReturn('admin');

		$this->userSessionMock->expects($this->any())
			->method('getUser')
			->willReturn($this->userMock);

		$this->fileMock->expects($this->any())
			->method('getMTime')
			->willReturn($fileMTime);

		if ($expectedStatus === 200) {
			$this->fileMock->expects($this->once())->method('putContent')->with($fileContents);
		} else {
			$this->fileMock->expects($this->never())->method('putContent');
		}

		$result = $this->controller->save($path, $fileContents, $mTime);
		$status = $result->getStatus();
		$data = $result->getData();

		$this->assertSame($expectedStatus, $status);
		if ($status === 200) {
			$this->assertArrayHasKey('mtime', $data);
			$this->assertArrayHasKey('size', $data);
		} else {
			$this->assertArrayHasKey('message', $data);
			$this->assertSame($expectedMessage, $data['message']);
		}
	}

	public function testFileTooBig() {
		$this->fileMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);
			
		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($this->fileStorageMock);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$this->userMock->expects($this->any())
			->method('getUID')
			->willReturn('admin');

		$this->userSessionMock->expects($this->any())
			->method('getUser')
			->willReturn($this->userMock);

		$this->fileMock->expects($this->any())
			->method('getSize')
			->willReturn(4194304 + 1);

		$result = $this->controller->load('/', 'foo.bar');
		$data = $result->getData();
		$status = $result->getStatus();
		$this->assertSame(400, $status);
		$this->assertArrayHasKey('message', $data);
		$this->assertSame('This file is too big to be opened. Please download the file instead.', $data['message']);
	}

	public function dataTestSave() {
		return [
			['/test.txt', 'file content', 65638643, 65638643, true, 200, ''],
			['', 'file content', 65638643, 65638643, true, 400, 'File path not supplied'],
			['/test.txt', 'file content', '', 65638643, true, 400, 'File mtime not supplied'],
			['/test.txt', 'file content', 0, 65638643, true, 400, 'File mtime not supplied'],
			['/test.txt', 'file content', 65638643, 32848548, true, 400, 'Cannot save file as it has been modified since opening'],
			['/test.txt', 'file content', 65638643, 65638643, false, 400, 'Insufficient permissions'],
		];
	}

	public function testLoadWithShare() {
		$filename = 'test.txt';
		$fileContent = 'test';

		$this->requestMock->expects($this->any())
			->method('getParam')
			->willReturn('token');

		$this->shareMock->expects($this->any())
			->method('getNode')
			->willReturn($this->fileMock);

		$this->shareManagerMock->expects($this->any())
			->method('getShareByToken')
			->willReturn($this->shareMock);

		$this->fileMock->expects($this->any())
			->method('getContent')
			->willReturn($fileContent);
			
		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($this->fileStorageMock);

		$this->shareMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$result = $this->controller->load('/', $filename);
		$data = $result->getData();
		$status = $result->getStatus();
		$this->assertSame($status, 200);

		$this->assertArrayHasKey('filecontents', $data);
		$this->assertArrayHasKey('writeable', $data);
		$this->assertArrayHasKey('locked', $data);
		$this->assertArrayHasKey('mime', $data);
		$this->assertArrayHasKey('mtime', $data);
		$this->assertSame($data['filecontents'], $fileContent);
	}

	public function testLoadWithShareFileDrop() {
		$filename = 'test.txt';
		$fileContent = 'test';

		$this->requestMock->expects($this->any())
			->method('getParam')
			->willReturn('token');

		$this->shareMock->expects($this->any())
			->method('getNode')
			->willReturn($this->fileMock);

		$this->shareManagerMock->expects($this->any())
			->method('getShareByToken')
			->willReturn($this->shareMock);

		$this->fileMock->expects($this->any())
			->method('getContent')
			->willReturn($fileContent);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($this->fileStorageMock);

		$this->shareMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_CREATE); // explicit no read permission

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$result = $this->controller->load('/', $filename);
		$data = $result->getData();
		$status = $result->getStatus();
		$this->assertSame($status, 400);
	}

	public function testLoadWithShareWithPassword() {
		$filename = 'test.txt';
		$fileContent = 'test';

		// need to replace the interface with the implementation to access the 'getSession' method
		$this->userSessionMock = $this->createMock(Session::class);
		$this->controller = new FileHandlingController(
			$this->appName,
			$this->requestMock,
			$this->l10nMock,
			$this->loggerMock,
			$this->shareManagerMock,
			$this->userSessionMock,
			$this->rootMock,
			$this->configMock
		);

		$this->requestMock->expects($this->any())
			->method('getParam')
			->willReturn('token');

		$this->shareMock->expects($this->any())
			->method('getNode')
			->willReturn($this->fileMock);

		$this->shareManagerMock->expects($this->any())
			->method('getShareByToken')
			->willReturn($this->shareMock);

		$this->fileMock->expects($this->any())
			->method('getContent')
			->willReturn($fileContent);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($this->fileStorageMock);

		$this->shareMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);
		$this->shareMock->expects($this->any())
			->method('getPassword')
			->willReturn('11111');
		$this->shareMock->expects($this->any())
			->method('getId')
			->willReturn(42);

		$session = $this->createMock(ISession::class);
		$session->expects($this->any())
			->method('get')
			->with('public_link_authenticated')
			->willReturn('42');
		$this->userSessionMock->expects($this->any())
			->method('getSession')
			->willReturn($session);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$result = $this->controller->load('/', $filename);
		$data = $result->getData();
		$status = $result->getStatus();
		$this->assertSame($status, 200);

		$this->assertArrayHasKey('filecontents', $data);
		$this->assertArrayHasKey('writeable', $data);
		$this->assertArrayHasKey('locked', $data);
		$this->assertArrayHasKey('mime', $data);
		$this->assertArrayHasKey('mtime', $data);
		$this->assertSame($data['filecontents'], $fileContent);
	}

	public function testLoadWithShareWithPasswordWrong() {
		$filename = 'test.txt';
		$fileContent = 'test';

		// need to replace the interface with the implementation to access the 'getSession' method
		$this->userSessionMock = $this->createMock(Session::class);
		$this->controller = new FileHandlingController(
			$this->appName,
			$this->requestMock,
			$this->l10nMock,
			$this->loggerMock,
			$this->shareManagerMock,
			$this->userSessionMock,
			$this->rootMock,
			$this->configMock
		);

		$this->requestMock->expects($this->any())
			->method('getParam')
			->willReturn('token');

		$this->shareMock->expects($this->any())
			->method('getNode')
			->willReturn($this->fileMock);

		$this->shareManagerMock->expects($this->any())
			->method('getShareByToken')
			->willReturn($this->shareMock);

		$this->fileMock->expects($this->any())
			->method('getContent')
			->willReturn($fileContent);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($this->fileStorageMock);

		$this->shareMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);
		$this->shareMock->expects($this->any())
			->method('getPassword')
			->willReturn('11111');
		$this->shareMock->expects($this->any())
			->method('getId')
			->willReturn(42);

		$session = $this->createMock(ISession::class);
		$session->expects($this->any())
			->method('get')
			->with('public_link_authenticated')
			->willReturn('48');
		$this->userSessionMock->expects($this->any())
			->method('getSession')
			->willReturn($session);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$result = $this->controller->load('/', $filename);
		$data = $result->getData();
		$status = $result->getStatus();
		$this->assertSame($status, 400);
	}

	public function testSaveWithShare() {
		$path = '/test.txt';
		$fileContent = 'test';
		$mTime = 65638643;
		$fileMTime = 65638643;

		$this->requestMock->expects($this->any())
			->method('getParam')
			->willReturn('token');

		$this->shareMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$this->shareMock->expects($this->any())
			->method('getNode')
			->willReturn($this->fileMock);

		$this->shareManagerMock->expects($this->any())
			->method('getShareByToken')
			->willReturn($this->shareMock);

		$this->fileMock->expects($this->any())
			->method('getMTime')
			->willReturn($fileMTime);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($this->fileStorageMock);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$this->fileMock->expects($this->once())->method('putContent')->with($fileContent);

		$result = $this->controller->save($path, $fileContent, $mTime);
		$status = $result->getStatus();
		$data = $result->getData();

		$this->assertSame(200, $status);
		$this->assertArrayHasKey('mtime', $data);
		$this->assertArrayHasKey('size', $data);
	}

	public function testLoadReadOnly() {
		$filename = 'test.txt';
		$fileContent = 'test';
		$fileMTime = 65638643;

		$this->userMock->expects($this->any())
			->method('getUID')
			->willReturn('admin');

		$this->userSessionMock->expects($this->any())
			->method('getUser')
			->willReturn($this->userMock);
			
		$this->fileMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_READ);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($this->fileStorageMock);

		$this->fileMock->expects($this->any())
			->method('getContent')
			->willReturn($fileContent);
		
		$this->fileMock->expects($this->any())
			->method('getMTime')
			->willReturn($fileMTime);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$result = $this->controller->load('/', $filename);
		$data = $result->getData();
		$status = $result->getStatus();
		$this->assertSame($status, 200);

		$this->assertArrayHasKey('filecontents', $data);
		$this->assertArrayHasKey('writeable', $data);
		$this->assertArrayHasKey('locked', $data);
		$this->assertArrayHasKey('mime', $data);
		$this->assertArrayHasKey('mtime', $data);
		$this->assertSame($data['filecontents'], $fileContent);
		$this->assertSame($data['writeable'], false);
		$this->assertSame($data['locked'], null);
	}

	public function dataLoadViewOnlyShare() {
		return [
			'Download nicht erlaubt' => [false, 403],
			'Download erlaubt' => [true, 200],
			'ohne Angabe' => [null, 200],
		];
	}

	/**
	 * Freigabe mit „Download nicht erlaubt“: Der Kern sperrt WebDAV-GET, der Editor
	 * darf den Inhalt dann auch nicht ausliefern.
	 *
	 * @dataProvider dataLoadViewOnlyShare
	 *
	 * @param bool|null $download Attribut permissions.download
	 * @param int $expectedStatus
	 */
	public function testLoadViewOnlyShare($download, $expectedStatus) {
		$this->userMock->method('getUID')->willReturn('ben');
		$this->userSessionMock->method('getUser')->willReturn($this->userMock);

		$attributes = $this->createMock(\OCP\Share\IAttributes::class);
		$attributes->method('getAttribute')
			->with('permissions', 'download')
			->willReturn($download);
		$this->shareMock->method('getAttributes')->willReturn($attributes);
		$sharedStorage = $this->getMockBuilder(\OCA\Files_Sharing\SharedStorage::class)
			->disableOriginalConstructor()
			->getMock();
		$sharedStorage->method('instanceOfStorage')
			->willReturnCallback(static function ($class) {
				return \ltrim($class, '\\') === 'OCA\Files_Sharing\SharedStorage';
			});
		$sharedStorage->method('getShare')->willReturn($this->shareMock);

		$this->fileMock->method('getStorage')->willReturn($sharedStorage);
		$this->fileMock->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		if ($expectedStatus === 200) {
			$this->fileMock->method('getContent')->willReturn('geheim');
		} else {
			$this->fileMock->expects($this->never())->method('getContent');
		}
		$this->rootMock->method('get')->willReturn($this->fileMock);

		$result = $this->controller->load('/', 'notiz.txt');
		$data = $result->getData();

		$this->assertSame($expectedStatus, $result->getStatus());
		if ($expectedStatus === 200) {
			$this->assertSame('geheim', $data['filecontents']);
			$this->assertFalse($data['writeable']);
		} else {
			$this->assertArrayNotHasKey('filecontents', $data);
			$this->assertSame('Cannot read the file. Not enough permissions', $data['message']);
		}
	}

	public function dataLoadAcquirePersistentLock() {
		return [
			[Constants::PERMISSION_ALL, null, true, 'test@test.com'],
			[Constants::PERMISSION_ALL, 'public-share', true, 'test@test.com'],
			[Constants::PERMISSION_READ, null, false, null],
			[Constants::PERMISSION_READ, 'public-share', false, null],
		];
	}
	/**
	 * @dataProvider dataLoadAcquirePersistentLock
	 *
	 * @param int $permissions
	 * @param string $shareToken
	 * @param bool $expectWritable
	 * @param string $expectLocked
	 */
	public function testLoadAcquirePersistentLock($permissions, $shareToken, $expectWritable, $expectLocked) {
		$filename = 'test.txt';
		$fileContent = 'test';
		$parentPath = '/test';
		$fileId = 1;
		$fileMTime = 65638643;
		$userId = 'test@test.com';

		$this->requestMock->expects($this->any())
			->method('getParam')
			->willReturn($shareToken);

		$this->shareMock->expects($this->any())
			->method('getPermissions')
			->willReturn($permissions);

		$this->shareMock->expects($this->any())
			->method('getNode')
			->willReturn($this->fileMock);

		$this->shareManagerMock->expects($this->any())
			->method('getShareByToken')
			->willReturn($this->shareMock);

		$parentFolderMock = $this->getMockBuilder(Folder::class)
			->disableOriginalConstructor()
			->getMock();

		$parentFolderMock->expects($this->any())
			->method('getPath')
			->willReturn($parentPath);

		$persistentLockMock = $this->getMockBuilder(ILock::class)
			->disableOriginalConstructor()
			->getMock();

		$persistentLockMock->expects($this->any())
			->method('getOwner')
			->willReturn($userId);

		$persistentLockMock->expects($this->any())
			->method('getToken')
			->willReturn('token');

		$persistentLockingStorageMock = $this->getMockBuilder(IPersistentLockingStorageTest::class)
			->disableOriginalConstructor()
			->getMock();

		$persistentLockingStorageMock->expects($this->any())
			->method('instanceOfStorage')
			->willReturnCallback(static fn ($class) => \ltrim($class, '\\') === IPersistentLockingStorage::class);

		$persistentLockingStorageMock->expects($this->any())
			->method('getLocks')
			->willReturn([]);

		if ($expectWritable) {
			$persistentLockingStorageMock->expects($this->once())
				->method('lockNodePersistent')
				->willReturn($persistentLockMock);
		} else {
			$persistentLockingStorageMock->expects($this->never())
				->method('lockNodePersistent');
		}

		$this->userMock->expects($this->any())
			->method('getUID')
			->willReturn($userId);

		$this->userSessionMock->expects($this->any())
			->method('getUser')
			->willReturn($this->userMock);
			
		$this->fileMock->expects($this->any())
			->method('getId')
			->willReturn($fileId);

		$this->fileMock->expects($this->any())
			->method('getInternalPath')
			->willReturn($parentPath . $filename);

		$this->fileMock->expects($this->any())
			->method('getPermissions')
			->willReturn($permissions);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($persistentLockingStorageMock);

		$this->fileMock->expects($this->any())
			->method('getContent')
			->willReturn($fileContent);
		
		$this->fileMock->expects($this->any())
			->method('getMTime')
			->willReturn($fileMTime);
			
		$this->fileMock->expects($this->any())
			->method('getParent')
			->willReturn($parentFolderMock);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$result = $this->controller->load($parentPath, $filename);
		$data = $result->getData();
		$status = $result->getStatus();
		$this->assertSame($status, 200);

		$this->assertArrayHasKey('filecontents', $data);
		$this->assertArrayHasKey('writeable', $data);
		$this->assertArrayHasKey('locked', $data);
		$this->assertArrayHasKey('mime', $data);
		$this->assertArrayHasKey('mtime', $data);
		$this->assertSame($data['filecontents'], $fileContent);
		$this->assertSame($data['writeable'], $expectWritable);
		$this->assertSame($data['locked'], $expectLocked);
	}

	/**
	 * Test that when there is lock from other app, load enforces read-only on a file
	 */
	public function testLoadWithPersistentLockFromOtherApp() {
		$filename = 'test.txt';
		$fileContent = 'test';
		$parentPath = '/test';
		$fileId = 1;
		$fileMTime = 65638643;

		$parentFolderMock = $this->getMockBuilder(Folder::class)
			->disableOriginalConstructor()
			->getMock();

		$parentFolderMock->expects($this->any())
			->method('getPath')
			->willReturn($parentPath);

		$persistentLockMock = $this->getMockBuilder(ILock::class)
			->disableOriginalConstructor()
			->getMock();

		$persistentLockMock->expects($this->any())
			->method('getOwner')
			->willReturn('test@test.com');

		$persistentLockMock->expects($this->any())
			->method('getToken')
			->willReturn('other-app-token');

		$persistentLockingStorageMock = $this->getMockBuilder(IPersistentLockingStorageTest::class)
			->disableOriginalConstructor()
			->getMock();

		$persistentLockingStorageMock->expects($this->any())
			->method('instanceOfStorage')
			->willReturnCallback(static fn ($class) => \ltrim($class, '\\') === IPersistentLockingStorage::class);

		$persistentLockingStorageMock->expects($this->any())
			->method('getLocks')
			->willReturn([$persistentLockMock]);

		$persistentLockingStorageMock->expects($this->never())
			->method('lockNodePersistent');

		$this->userMock->expects($this->any())
			->method('getUID')
			->willReturn('admin');

		$this->userSessionMock->expects($this->any())
			->method('getUser')
			->willReturn($this->userMock);
			
		$this->fileMock->expects($this->any())
			->method('getId')
			->willReturn($fileId);

		$this->fileMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($persistentLockingStorageMock);

		$this->fileMock->expects($this->any())
			->method('getContent')
			->willReturn($fileContent);
		
		$this->fileMock->expects($this->any())
			->method('getMTime')
			->willReturn($fileMTime);
			
		$this->fileMock->expects($this->any())
			->method('getParent')
			->willReturn($parentFolderMock);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$result = $this->controller->load($parentPath, $filename);
		$data = $result->getData();
		$status = $result->getStatus();
		$this->assertSame($status, 200);

		$this->assertArrayHasKey('filecontents', $data);
		$this->assertArrayHasKey('writeable', $data);
		$this->assertArrayHasKey('locked', $data);
		$this->assertArrayHasKey('mime', $data);
		$this->assertArrayHasKey('mtime', $data);
		$this->assertSame($data['filecontents'], $fileContent);
		$this->assertSame($data['writeable'], false);
		$this->assertSame($data['locked'], 'test@test.com');
	}

	/** Schlüssel der Sperr-Token, wie ihn der Controller aus dem Instanzgeheimnis ableitet */
	private static function lockTokenKey(): string {
		return \hash_hmac('sha256', 'files_texteditor-persistent-lock', self::INSTANCE_SECRET);
	}

	/**
	 * Ein Sperr-Token, das mit dem früheren festen Schlüssel aus dem Code berechnet
	 * wurde, gilt nicht: Sonst kann jeder mit Schreibrecht die Sperre eines anderen
	 * Bearbeiters nachbilden. Die Datei bleibt für ihn schreibgeschützt.
	 */
	public function testLoadRejectsLockTokenFromPublicKey() {
		$filename = 'test.txt';
		$parentPath = '/test';
		$fileId = 1;
		$userId = 'test@test.com';

		$this->requestMock->method('getParam')->willReturn(null);

		$parentFolderMock = $this->createMock(Folder::class);
		$parentFolderMock->method('getPath')->willReturn($parentPath);

		$forgedToken = JWT::encode([
			'uid' => $userId,
			'st' => '',
			'fid' => $fileId,
			'fpp' => $parentPath,
		], self::OLD_PUBLIC_KEY, 'HS256');

		$persistentLockMock = $this->createMock(ILock::class);
		$persistentLockMock->method('getOwner')->willReturn('ben via Text Editor');
		$persistentLockMock->method('getToken')->willReturn($forgedToken);

		$persistentLockingStorageMock = $this->createMock(IPersistentLockingStorageTest::class);
		$persistentLockingStorageMock->method('instanceOfStorage')
			->willReturnCallback(static fn ($class) => \ltrim($class, '\\') === IPersistentLockingStorage::class);
		$persistentLockingStorageMock->method('getLocks')->willReturn([$persistentLockMock]);
		$persistentLockingStorageMock->expects($this->never())->method('lockNodePersistent');

		$this->userMock->method('getUID')->willReturn($userId);
		$this->userSessionMock->method('getUser')->willReturn($this->userMock);

		$this->fileMock->method('getId')->willReturn($fileId);
		$this->fileMock->method('getInternalPath')->willReturn($parentPath . $filename);
		$this->fileMock->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);
		$this->fileMock->method('getStorage')->willReturn($persistentLockingStorageMock);
		$this->fileMock->method('getContent')->willReturn('test');
		$this->fileMock->method('getMTime')->willReturn(65638643);
		$this->fileMock->method('getParent')->willReturn($parentFolderMock);
		$this->rootMock->method('get')->willReturn($this->fileMock);

		$result = $this->controller->load($parentPath, $filename);
		$data = $result->getData();
		$this->assertSame(200, $result->getStatus());
		$this->assertFalse($data['writeable']);
		$this->assertSame('ben via Text Editor', $data['locked']);
	}

	public function dataSaveVerifyPersistentLock() {
		return [
			[null],
			['public-share'],
		];
	}
	/**
	 * @dataProvider dataSaveVerifyPersistentLock
	 *
	 * @param string $shareToken
	 */
	public function testSaveVerifyPersistentLock($shareToken) {
		$filename = 'test.txt';
		$fileContent = 'test';
		$parentPath = '/test';
		$fileId = 1;
		$mTime = 65638643;
		$fileMTime = 65638643;
		$userId = 'test@test.com';

		$this->requestMock->expects($this->any())
			->method('getParam')
			->willReturn($shareToken);

		$this->shareMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$this->shareMock->expects($this->any())
			->method('getNode')
			->willReturn($this->fileMock);

		$this->shareManagerMock->expects($this->any())
			->method('getShareByToken')
			->willReturn($this->shareMock);

		$parentFolderMock = $this->getMockBuilder(Folder::class)
			->disableOriginalConstructor()
			->getMock();

		$parentFolderMock->expects($this->any())
			->method('getPath')
			->willReturn($parentPath);

		$persistentLockMock = $this->getMockBuilder(ILock::class)
			->disableOriginalConstructor()
			->getMock();

		if ($shareToken) {
			$owner = 'Public Link User via Text Editor';
			$token = JWT::encode([
				'uid' => '',
				'st' => $shareToken,
				'fid' => $fileId,
				'fpp' => $parentPath,
			], self::lockTokenKey(), 'HS256');
		} else {
			$owner = $userId . ' via Text Editor';
			$token = JWT::encode([
				'uid' => $userId,
				'st' => '',
				'fid' => $fileId,
				'fpp' => $parentPath,
			], self::lockTokenKey(), 'HS256');
		}

		$persistentLockMock
			->method('getOwner')
			->willReturn($owner);

		$persistentLockMock
			->method('getToken')
			->willReturn($token);

		$persistentLockingStorageMock = $this->getMockBuilder(IPersistentLockingStorageTest::class)
			->disableOriginalConstructor()
			->getMock();

		$persistentLockingStorageMock->expects($this->any())
			->method('instanceOfStorage')
			->willReturnCallback(static fn ($class) => \ltrim($class, '\\') === IPersistentLockingStorage::class);

		$persistentLockingStorageMock->expects($this->any())
			->method('getLocks')
			->willReturn([$persistentLockMock]);

		$persistentLockingStorageMock->expects($this->once())
			->method('lockNodePersistent')
			->with($this->stringContains($parentPath . $filename), $this->equalTo([
				'token' => $token,
				'owner' => $owner
			]))
			->willReturn($persistentLockMock);

		$this->userMock->expects($this->any())
			->method('getUID')
			->willReturn($userId);

		$this->userMock->expects($this->any())
			->method('getDisplayName')
			->willReturn($userId);

		$this->userSessionMock->expects($this->any())
			->method('getUser')
			->willReturn($this->userMock);
			
		$this->fileMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($persistentLockingStorageMock);

		$this->fileMock->expects($this->any())
			->method('getId')
			->willReturn($fileId);

		$this->fileMock->expects($this->any())
			->method('getInternalPath')
			->willReturn($parentPath . $filename);

		$this->fileMock->expects($this->any())
			->method('getMTime')
			->willReturn($fileMTime);

		$this->fileMock->expects($this->any())
			->method('getParent')
			->willReturn($parentFolderMock);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$result = $this->controller->save($parentPath . $filename, $fileContent, $mTime);
		$status = $result->getStatus();
		$data = $result->getData();

		$this->assertSame(200, $status);
		$this->assertArrayHasKey('mtime', $data);
		$this->assertArrayHasKey('size', $data);
	}

	/**
	 * Test that when there is lock from other app, save is not allowed
	 */
	public function testSaveWithPersistentLockFromOtherApp() {
		$filename = 'test.txt';
		$fileContent = 'test';
		$parentPath = '/test';
		$fileId = 1;
		$mTime = 65638643;
		$fileMTime = 65638643;

		$parentFolderMock = $this->getMockBuilder(Folder::class)
			->disableOriginalConstructor()
			->getMock();

		$parentFolderMock->expects($this->any())
			->method('getPath')
			->willReturn($parentPath);

		$persistentLockMock = $this->getMockBuilder(ILock::class)
			->disableOriginalConstructor()
			->getMock();

		$persistentLockMock->expects($this->any())
			->method('getOwner')
			->willReturn('test@test.com');

		$persistentLockMock->expects($this->any())
			->method('getToken')
			->willReturn('other-app-token');

		$persistentLockingStorageMock = $this->getMockBuilder(IPersistentLockingStorageTest::class)
			->disableOriginalConstructor()
			->getMock();

		$persistentLockingStorageMock->expects($this->any())
			->method('instanceOfStorage')
			->willReturnCallback(static fn ($class) => \ltrim($class, '\\') === IPersistentLockingStorage::class);

		$persistentLockingStorageMock->expects($this->any())
			->method('getLocks')
			->willReturn([$persistentLockMock]);

		$persistentLockingStorageMock->expects($this->never())
			->method('lockNodePersistent');

		$this->userMock->expects($this->any())
			->method('getUID')
			->willReturn('admin');

		$this->userSessionMock->expects($this->any())
			->method('getUser')
			->willReturn($this->userMock);
			
		$this->fileMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($persistentLockingStorageMock);

		$this->fileMock->expects($this->any())
			->method('getId')
			->willReturn($fileId);

		$this->fileMock->expects($this->any())
			->method('getMTime')
			->willReturn($fileMTime);

		$this->fileMock->expects($this->any())
			->method('getParent')
			->willReturn($parentFolderMock);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$result = $this->controller->save($parentPath . $filename, $fileContent, $mTime);
		$status = $result->getStatus();
		$data = $result->getData();

		$this->assertSame(400, $status);
		$this->assertArrayHasKey('message', $data);
		$this->assertSame('Cannot save file as it is locked by test@test.com.', $data['message']);
	}

	public function dataClose() {
		return [
			[true, null],
			[true, 'public-share'],
			[false, null],
			[false, 'public-share'],
		];
	}
	/**
	 * @dataProvider dataClose
	 *
	 * @param bool $isLocked
	 * @param string $shareToken
	 */
	public function testClose($isLocked, $shareToken) {
		$filename = 'test.txt';
		$parentPath = '/test';
		$fileId = 1;
		$fileMTime = 65638643;
		$userId = 'test@test.com';

		$this->requestMock->expects($this->any())
			->method('getParam')
			->willReturn($shareToken);

		$this->shareMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$this->shareMock->expects($this->any())
			->method('getNode')
			->willReturn($this->fileMock);

		$this->shareManagerMock->expects($this->any())
			->method('getShareByToken')
			->willReturn($this->shareMock);

		$parentFolderMock = $this->getMockBuilder(Folder::class)
			->disableOriginalConstructor()
			->getMock();

		$parentFolderMock->expects($this->any())
			->method('getPath')
			->willReturn($parentPath);

		$persistentLockMock = $this->getMockBuilder(ILock::class)
			->disableOriginalConstructor()
			->getMock();

		if ($shareToken) {
			$token = JWT::encode([
				'uid' => '',
				'st' => $shareToken,
				'fid' => $fileId,
				'fpp' => $parentPath,
			], self::lockTokenKey(), 'HS256');
		} else {
			$token = JWT::encode([
				'uid' => $userId,
				'st' => '',
				'fid' => $fileId,
				'fpp' => $parentPath,
			], self::lockTokenKey(), 'HS256');
		}

		$persistentLockMock->expects($this->never())
			->method('getOwner');

		$persistentLockMock->expects($this->any())
			->method('getToken')
			->willReturn($token);

		$persistentLockingStorageMock = $this->getMockBuilder(IPersistentLockingStorageTest::class)
			->disableOriginalConstructor()
			->getMock();

		$persistentLockingStorageMock->expects($this->any())
			->method('instanceOfStorage')
			->willReturnCallback(static fn ($class) => \ltrim($class, '\\') === IPersistentLockingStorage::class);

		if ($isLocked) {
			$persistentLockingStorageMock->expects($this->any())
				->method('getLocks')
				->willReturn([$persistentLockMock]);

			$persistentLockingStorageMock->expects($this->once())
				->method('unlockNodePersistent')
				->with($this->stringContains($parentPath . $filename), $this->equalTo([
					'token' => $token
				]))
				->willReturn(true);
		} else {
			$persistentLockingStorageMock->expects($this->any())
				->method('getLocks')
				->willReturn([]);

			$persistentLockingStorageMock->expects($this->never())
				->method('unlockNodePersistent');
		}

		$this->userMock->expects($this->any())
			->method('getUID')
			->willReturn($userId);

		$this->userMock->expects($this->any())
			->method('getDisplayName')
			->willReturn($userId);

		$this->userSessionMock->expects($this->any())
			->method('getUser')
			->willReturn($this->userMock);
			
		$this->fileMock->expects($this->any())
			->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$this->fileMock->expects($this->any())
			->method('getStorage')
			->willReturn($persistentLockingStorageMock);

		$this->fileMock->expects($this->any())
			->method('getId')
			->willReturn($fileId);

		$this->fileMock->expects($this->any())
			->method('getInternalPath')
			->willReturn($parentPath . $filename);

		$this->fileMock->expects($this->any())
			->method('getMTime')
			->willReturn($fileMTime);

		$this->fileMock->expects($this->any())
			->method('getParent')
			->willReturn($parentFolderMock);

		$this->rootMock->expects($this->any())
			->method('get')
			->willReturn($this->fileMock);

		$result = $this->controller->close($parentPath . $filename);
		$status = $result->getStatus();
		$data = $result->getData();

		$this->assertSame(200, $status);
		$this->assertEmpty($data);
	}
}
