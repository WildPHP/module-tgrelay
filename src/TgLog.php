<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\HttpClient\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use unreal4u\TelegramAPI\Telegram\Types\File;
use Yoshi2889\Container\ComponentInterface;
use Yoshi2889\Container\ComponentTrait;

/**
 * Class TgLog
 * @package WildPHP\Modules\TGRelay
 *
 * Simple wrapper around the TgLog object, to allow it to sit in a container.
 */
class TgLog extends \unreal4u\TelegramAPI\TgLog implements ComponentInterface
{
	use ComponentTrait;

	/**
	 * @var string
	 */
	protected $botID = '';

	/**
	 * @var \React\HttpClient\Client
	 */
	protected $reactHttpClient;

	/**
	 * TgLog constructor. Needed because BotToken is private in the original class.
	 *
	 * @param string $botToken
	 * @param LoopInterface $loop
	 * @param LoggerInterface|null $logger
	 * @param Client|null $client
	 */
	public function __construct($botToken, LoopInterface $loop, LoggerInterface $logger = null, Client $client = null)
	{
		$this->botID = $botToken;
		parent::__construct($botToken, $logger, $client);
		$this->reactHttpClient = new \React\HttpClient\Client($loop);
	}

	/**
	 * @param File $file
	 *
	 * @return PromiseInterface
	 */
	public function downloadFileAsync(File $file): PromiseInterface
	{
		$url = 'https://api.telegram.org/file/bot' . $this->botID . '/' . $file->file_path;

		$deferred = new Deferred();

		$request = $this->reactHttpClient->request('GET', $url);
		$request->on('response', function (Response $response) use ($deferred, $request, $file)
		{
			if ($response->getCode() != 200)
			{
				$deferred->reject(new DownloadException('Response was not successful (status code != 200)'));
				$request->end();
				return;
			}

			$body = '';
			$response->on('data', function ($chunk) use (&$body)
			{
				$body .= $chunk;
			});

			$response->on('end', function () use ($deferred, &$body, $file)
			{
				$deferred->resolve(new DownloadedFile($body, $file));
			});
		});
		$request->on('error', function (\Exception $e) use ($deferred)
		{

			$deferred->reject($e);
		});
		$request->end();

		return $deferred->promise();
	}

	/**
	 * @param string $chatID
	 *
	 * @return string
	 */
	public function makeFileStructure(string $chatID): string
	{
		$basePath = WPHP_ROOT_DIR . '/tgstorage';
		$idHash = sha1($chatID);

		$structure = [
			$basePath,
			$basePath . '/' . $idHash,
			$basePath . '/' . $idHash . '/photos',
			$basePath . '/' . $idHash . '/documents',
			$basePath . '/' . $idHash . '/animations',
			$basePath . '/' . $idHash . '/stickers',
			$basePath . '/' . $idHash . '/video',
			$basePath . '/' . $idHash . '/voice'
		];

		foreach ($structure as $item)
		{
			if (!file_exists($item))
				mkdir($item);
		}

		return $basePath . '/' . $idHash;
	}
}